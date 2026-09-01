<?php

namespace App\Services\MercadoLivre\Services;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\DTOs\OrderDTO;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderService
{
    public function __construct(
        private readonly MercadoLivreClient $client,
        private readonly OrderImportService $importer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listRecentOrders(int $mlUserId): array
    {
        return $this->client->get('orders/search/recent', ['seller' => $mlUserId]);
    }

    /**
     * Pedidos do vendedor num intervalo de datas (não só os "recentes") —
     * usado pelo backfill (App\Console\Commands\SyncMercadoLivreOrders,
     * 2026-08-06, refeito no mesmo dia pra escopar por data em vez de
     * trazer o histórico inteiro — pedido explícito do usuário) que traz
     * pro banco local qualquer venda que nunca chegou por webhook (ex: cron
     * parado, app desconectado num período). Filtro de data real da API
     * (order.date_created.from/to, confirmado ao vivo) — não filtra
     * client-side depois de já ter baixado tudo. orders/search pagina por
     * offset/limit real (confirmado ao vivo).
     *
     * @return array<int, string>
     */
    public function listOrderIds(int $mlUserId, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array
    {
        $ids = [];
        $offset = 0;
        $limit = 50;

        do {
            $page = $this->client->get('orders/search', [
                'seller' => $mlUserId,
                'order.date_created.from' => $from->toIso8601String(),
                'order.date_created.to' => $to->toIso8601String(),
                'offset' => $offset,
                'limit' => $limit,
            ]);

            $results = $page['results'] ?? [];

            foreach ($results as $order) {
                if (isset($order['id'])) {
                    $ids[] = (string) $order['id'];
                }
            }

            $total = (int) ($page['paging']['total'] ?? 0);
            $offset += $limit;
        } while ($offset < $total);

        return $ids;
    }

    public function getOrder(string $orderId): OrderDTO
    {
        $order = $this->client->get("orders/{$orderId}");

        return new OrderDTO(
            id: (int) $order['id'],
            status: $order['status'],
            total_amount: (float) $order['total_amount'],
            currency_id: $order['currency_id'],
            date_created: $order['date_created'],
            buyer: $order['buyer'] ?? [],
            order_items: $order['order_items'] ?? [],
            shipping: $order['shipping'] ?? [],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderItems(string $orderId): array
    {
        return $this->client->get("orders/{$orderId}")['order_items'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateOrderStatus(string $orderId, string $status): array
    {
        return $this->client->put("orders/{$orderId}", ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getShippingInfo(string $shipmentId): array
    {
        return $this->client->get("shipments/{$shipmentId}");
    }

    /**
     * Dados de faturamento reais do comprador — não vêm no payload normal do
     * pedido (confirmado, só tem nome/endereço ali), mas a Mercado Livre
     * mantém esse endpoint dedicado especificamente pra emissão de nota
     * fiscal. Nunca lança: em qualquer falha (endpoint indisponível, sem
     * billing_info) volta tudo null — quem chama decide o que fazer com
     * "sem CPF disponível", não é motivo pra derrubar a importação do
     * pedido inteira.
     *
     * `state_registration` (Inscrição Estadual) não é exposta por esse
     * endpoint pra nenhum tipo de comprador — a ML não coleta esse dado do
     * lado do consumidor — então fica sempre null aqui; existe no retorno
     * só pra já casar com o formato que o NF-e builder espera caso um dia
     * apareça outra fonte pra ela.
     *
     * @return array{document: ?string, state_registration: ?string, taxpayer_type: ?string}
     */
    public function getBuyerBillingData(string $orderId): array
    {
        $empty = ['document' => null, 'state_registration' => null, 'taxpayer_type' => null];

        try {
            $response = $this->client->get("orders/{$orderId}/billing_info");
        } catch (Throwable $exception) {
            Log::channel('mercadolivre')->warning('mercadolivre.billing_info_failed', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);

            return $empty;
        }

        $docNumber = $response['billing_info']['doc_number'] ?? null;
        $docType = $response['billing_info']['doc_type'] ?? null;

        // BUG REAL 2026-09-01 (pedido #1165, nota 810 REJEITADA pela SEFAZ:
        // "232 - IE do destinatário não informada"): comprador CNPJ
        // contribuinte (52.029.695 JEFFERSON QUEIROZ RODRIGUES DA SILVA,
        // IE 657842247116). O Mercado Livre MANDA a inscrição estadual e o
        // tipo de contribuinte, mas dentro de `additional_info` — uma
        // lista de {type, value}, não campos soltos em billing_info — e
        // esta função devolvia state_registration SEMPRE null, jogando
        // fora o dado que já estava na resposta. Sem IE, o XML saía com
        // indIEDest=9 (não contribuinte) pra um CNPJ contribuinte, que a
        // SEFAZ recusa. Valia pra TODA venda de CNPJ do canal, não só
        // esta.
        $additional = collect($response['billing_info']['additional_info'] ?? [])
            ->mapWithKeys(fn ($entry) => [strtoupper((string) ($entry['type'] ?? '')) => $entry['value'] ?? null]);

        $stateRegistration = preg_replace('/\D/', '', (string) ($additional['STATE_REGISTRATION'] ?? '')) ?: null;
        $taxpayerLabel = mb_strtolower(trim((string) ($additional['TAXPAYER_TYPE_ID'] ?? '')));
        $isCompany = strtoupper((string) $docType) === 'CNPJ';

        return [
            'document' => $docNumber ? preg_replace('/\D/', '', (string) $docNumber) : null,
            'state_registration' => $stateRegistration,
            // Vocabulário do indIEDest da NF-e (ver NFeXmlBuilderService),
            // não o do canal: é isso que decide se a nota leva IE ou não.
            // Pessoa física nunca é contribuinte; CNPJ sem IE informada
            // entra como isento (indIEDest=2), que é o que a SEFAZ espera
            // pra empresa sem inscrição estadual.
            'taxpayer_type' => match (true) {
                ! $isCompany => 'nao_contribuinte',
                str_contains($taxpayerLabel, 'isento') => 'isento',
                $stateRegistration !== null => 'contribuinte',
                default => 'isento',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.orders', $payload);

        if (! preg_match('#/orders/(\d+)#', $payload['resource'] ?? '', $matches)) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.webhook.orders.unparseable_resource', $payload);

            return;
        }

        try {
            $this->importer->import(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $matches[1]);
        } catch (Throwable $exception) {
            Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.webhook.orders.import_failed', [
                'order_id' => $matches[1],
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
