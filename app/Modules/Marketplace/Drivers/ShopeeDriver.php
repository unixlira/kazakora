<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shopee — API docs: https://open.shopee.com (Open Platform, partner approval required).
 *
 * OAuth2 authorization + HMAC-signed requests. Product publishing goes
 * through v2.product.add_item, images are uploaded first via
 * v2.media_space.upload_image and referenced by image id, stock through
 * v2.product.update_stock.
 */
class ShopeeDriver extends AbstractMarketplaceDriver
{
    public function __construct(private readonly ShopeeClient $client) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_SHOPEE;
    }

    /**
     * Lista todos os itens ativos (NORMAL) já publicados na loja Shopee,
     * direto pela API — usado por ShopeeProductImportService pra vincular
     * anúncios já existentes na Shopee (feitos fora do Kazakora) a produtos
     * locais, sem precisar cadastrar tudo nem republicar nada. Confirmado
     * contra a conta real 2026-08-06 (get_item_list + get_item_base_info,
     * paginado — a loja tinha 33 itens, has_next_page=true na primeira
     * página de 20).
     *
     * @return array<int, array{external_id: string, name: string, price: ?float}>
     */
    public function fetchOwnItems(): array
    {
        $this->ensureConfigured();

        $itemIds = [];
        $offset = 0;
        $pageSize = 50;

        do {
            $page = $this->client->get('/api/v2/product/get_item_list', [
                'offset' => $offset,
                'page_size' => $pageSize,
                'item_status' => 'NORMAL',
            ]);

            $items = $page['response']['item'] ?? [];

            foreach ($items as $item) {
                if (isset($item['item_id'])) {
                    $itemIds[] = (int) $item['item_id'];
                }
            }

            $hasNext = (bool) ($page['response']['has_next_page'] ?? false);
            $offset += $pageSize;
        } while ($hasNext);

        $result = [];

        // get_item_base_info aceita no máximo 50 ids por chamada.
        foreach (array_chunk($itemIds, 50) as $chunk) {
            $base = $this->client->get('/api/v2/product/get_item_base_info', [
                'item_id_list' => implode(',', $chunk),
            ]);

            foreach ($base['response']['item_list'] ?? [] as $item) {
                $result[] = [
                    'external_id' => (string) $item['item_id'],
                    'name' => (string) ($item['item_name'] ?? ''),
                    'price' => isset($item['price_info'][0]['current_price'])
                        ? (float) $item['price_info'][0]['current_price']
                        : null,
                ];
            }
        }

        return $result;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: upload $product->images via v2.media_space.upload_image,
        // then call v2.product.add_item with the returned image ids and
        // the category-specific attributes from $listing->attributes.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call v2.product.update_stock with $product->stock.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call v2.product.unlist_item.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    /**
     * order_sn de todos os pedidos num intervalo de datas — usado pelo
     * backfill (App\Console\Commands\SyncShopeeOrders, 2026-08-06, refeito
     * no mesmo dia pra escopar por data em vez de trazer o histórico
     * inteiro — pedido explícito do usuário). get_order_list só aceita
     * janelas de até 15 dias por chamada (confirmado ao vivo — erro
     * explícito da API acima disso), então varre $from..$to em fatias de
     * 15 dias. Paginação dentro de cada fatia via next_cursor/more.
     *
     * @return array<int, string>
     */
    public function listOrderSns(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array
    {
        $this->ensureConfigured();

        $sns = [];
        $sliceStart = $from->copy();

        while ($sliceStart->lt($to)) {
            $sliceEnd = $sliceStart->copy()->addDays(15)->min($to);
            $cursor = '';

            do {
                $page = $this->client->get('/api/v2/order/get_order_list', array_filter([
                    'time_range_field' => 'create_time',
                    'time_from' => $sliceStart->timestamp,
                    'time_to' => $sliceEnd->timestamp,
                    'page_size' => 50,
                    'cursor' => $cursor,
                ], fn ($value) => $value !== ''));

                $orders = $page['response']['order_list'] ?? [];

                foreach ($orders as $order) {
                    if (isset($order['order_sn'])) {
                        $sns[] = (string) $order['order_sn'];
                    }
                }

                $more = (bool) ($page['response']['more'] ?? false);
                $cursor = (string) ($page['response']['next_cursor'] ?? '');
            } while ($more && $cursor !== '');

            $sliceStart = $sliceEnd;
        }

        return array_values(array_unique($sns));
    }

    /**
     * `v2.order.get_order_detail` — confirmado ao vivo 2026-08-06 contra
     * 93 pedidos reais da loja conectada; os nomes de campo abaixo batem
     * com o payload de verdade, não só com a doc.
     */
    public function importOrder(string $externalOrderId): array
    {
        $this->ensureConfigured();

        $response = $this->client->get('/api/v2/order/get_order_detail', [
            'order_sn_list' => $externalOrderId,
            'response_optional_fields' => 'buyer_username,recipient_address,item_list,total_amount,order_status,estimated_shipping_fee,actual_shipping_fee',
        ]);

        $order = $response['response']['order_list'][0] ?? null;

        if (! $order) {
            throw new RuntimeException("Pedido {$externalOrderId} não encontrado na Shopee.");
        }

        $address = $order['recipient_address'] ?? [];
        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($order['item_list'] ?? [] as $item) {
            $externalItemId = (string) ($item['item_id'] ?? '');
            $quantity = (int) ($item['model_quantity_purchased'] ?? 0);
            $unitPrice = (float) ($item['model_discounted_price'] ?? $item['model_original_price'] ?? 0);

            if ($externalItemId === '' || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;
            // external_name é o nome real do anúncio na Shopee (item_name)
            // + a variação (model_name, quando existe e não é o valor
            // padrão "-" que a Shopee usa pra produto sem variação de
            // verdade) — usado como fallback pelo OrderImportService
            // quando o item ainda não tem produto local mapeado (achado
            // real 2026-08-06, mesmo gap existia no driver do Mercado
            // Livre — corrigido nos dois juntos).
            $itemName = (string) ($item['item_name'] ?? '');
            $modelName = (string) ($item['model_name'] ?? '');
            $externalName = $modelName !== '' && $modelName !== '-'
                ? trim("{$itemName} - {$modelName}")
                : ($itemName ?: null);

            $items[] = [
                'external_id' => $externalItemId,
                'external_name' => $externalName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $shippingCost = (float) ($order['actual_shipping_fee'] ?? $order['estimated_shipping_fee'] ?? 0);
        $total = (float) ($order['total_amount'] ?? ($itemsSubtotal + $shippingCost));

        return [
            'external_order_id' => (string) ($order['order_sn'] ?? $externalOrderId),
            'status' => $this->mapOrderStatus((string) ($order['order_status'] ?? '')),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round($shippingCost, 2),
            'total' => round($total, 2),
            'buyer_name' => $address['name'] ?? ($order['buyer_username'] ?? 'Comprador Shopee'),
            'buyer_phone' => $address['phone'] ?? null,
            'shipping_zip' => $address['zipcode'] ?? '00000000',
            'shipping_street' => $address['full_address'] ?? 'Não informado',
            'shipping_number' => 'S/N',
            'shipping_complement' => null,
            'shipping_neighborhood' => $address['district'] ?? 'Não informado',
            'shipping_city' => $address['city'] ?? 'Não informado',
            'shipping_state' => $this->extractState($address['state'] ?? 'NA'),
            'external_shipment_id' => null,
            // Mesmo motivo do MercadoLivreDriver — create_time é a data real
            // da venda na Shopee (unix timestamp, campo base do
            // get_order_detail), usado como created_at real do pedido em vez
            // de now() no backfill.
            'placed_at' => isset($order['create_time'])
                ? \Illuminate\Support\Carbon::createFromTimestamp((int) $order['create_time'])
                : null,
            'items' => $items,
        ];
    }

    /**
     * Vocabulário real de `order_status` da Shopee: UNPAID, READY_TO_SHIP,
     * PROCESSED, SHIPPED, TO_CONFIRM_RECEIVE, COMPLETED, CANCELLED,
     * TO_RETURN, IN_CANCEL. Mapeamento conservador — qualquer coisa não
     * reconhecida cai em "aguardando pagamento" em vez de assumir que já
     * foi pago (mesma cautela que MercadoLivreDriver::mapOrderStatus() já
     * aplica).
     */
    private function mapOrderStatus(string $status): string
    {
        return match ($status) {
            'READY_TO_SHIP', 'PROCESSED', 'TO_CONFIRM_RECEIVE' => Order::STATUS_PAID,
            'SHIPPED' => Order::STATUS_SHIPPED,
            'COMPLETED' => Order::STATUS_COMPLETED,
            'CANCELLED', 'IN_CANCEL', 'TO_RETURN' => Order::STATUS_CANCELLED,
            default => Order::STATUS_AWAITING_PAYMENT,
        };
    }

    /**
     * `shipping_state` na tabela orders é varchar(2) (limite que já mordeu
     * o Mercado Livre uma vez, que devolvia "BR-SP" em vez da sigla — ver
     * histórico). Achado real 2026-08-06, rodando o backfill contra pedidos
     * reais: a Shopee devolve o NOME COMPLETO do estado ("São Paulo"), não
     * a sigla — e `substr($state, 0, 2)` num nome acentuado corta no meio
     * do caractere multi-byte de "ã"/"é"/etc., gerando um byte UTF-8
     * inválido que o MySQL rejeita na hora do insert (33 de 93 pedidos
     * reais falharam com "Incorrect string value" antes desse fix). Mapa
     * nome completo → sigla, comparado sem acento (evita depender de a
     * Shopee mandar sempre com a acentuação "certa"); mb_substr como
     * fallback pra qualquer coisa não reconhecida, pelo menos não quebra
     * o insert mesmo se vier um valor novo/inesperado.
     */
    private const STATE_NAMES_TO_UF = [
        'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM',
        'BAHIA' => 'BA', 'CEARA' => 'CE', 'DISTRITO FEDERAL' => 'DF',
        'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO', 'MARANHAO' => 'MA',
        'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS', 'MINAS GERAIS' => 'MG',
        'PARA' => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR', 'PERNAMBUCO' => 'PE',
        'PIAUI' => 'PI', 'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN',
        'RIO GRANDE DO SUL' => 'RS', 'RONDONIA' => 'RO', 'RORAIMA' => 'RR',
        'SANTA CATARINA' => 'SC', 'SAO PAULO' => 'SP', 'SERGIPE' => 'SE',
        'TOCANTINS' => 'TO',
    ];

    private function extractState(string $state): string
    {
        $normalized = strtoupper(trim(Str::ascii($state)));

        if (isset(self::STATE_NAMES_TO_UF[$normalized])) {
            return self::STATE_NAMES_TO_UF[$normalized];
        }

        return mb_substr($normalized, 0, 2) ?: 'NA';
    }

    /**
     * `v2.order.upload_invoice_doc` (POST /api/v2/order/upload_invoice_doc),
     * multipart: order_sn, file_type=4 (XML), file. Aceita o XML da NF-e
     * direto, sem precisar do DANFE. Endpoint específico BR (e PH),
     * confirmado no schema oficial da Open Platform.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        if (! $invoice->xml_path) {
            throw new RuntimeException('Nota fiscal sem XML disponível para envio.');
        }

        $xml = Storage::disk('local')->get($invoice->xml_path);
        $filename = "nfe-{$invoice->chave_acesso}.xml";

        try {
            $response = $this->client->postMultipart(
                '/api/v2/order/upload_invoice_doc',
                ['order_sn' => $order->external_order_id, 'file_type' => 4],
                ['contents' => $xml, 'filename' => $filename],
            );

            return ['status' => 'sent', 'external_reference' => $order->external_order_id, 'response' => $response];
        } catch (ShopeeException $exception) {
            return ['status' => 'error', 'external_reference' => null, 'response' => ['error' => $exception->getMessage(), 'context' => $exception->context]];
        }
    }

    /**
     * Padrão aceito como "Expresso" no nome do transportador que a Shopee
     * devolve em `shipping_carrier` (ex: "SPX Express", "Entrega Expressa").
     * A loja só opera com Shopee Express habilitado no Seller Center — o
     * comprador escolhe a transportadora no checkout da Shopee, o vendedor
     * não escolhe via API (get_shipping_parameter só junta o que falta pra
     * UM transportador já decidido, não deixa trocar). Esse regex é uma
     * checagem defensiva de que a suposição continua válida, não uma
     * seleção — se um pedido vier com outro transportador, o envio ainda é
     * confirmado normalmente (não vale travar uma venda real por causa
     * disso), só fica registrado no log e na linha do tempo do pedido pra
     * o usuário perceber e investigar no Seller Center.
     */
    private const EXPRESS_CARRIER_PATTERN = '/express|expresso|expressa|spx/i';

    /**
     * get_shipping_parameter diz quais métodos o pedido suporta e, pro
     * dropoff, devolve os branch_id disponíveis — usa o primeiro (conta com
     * um único ponto de coleta configurado não precisa de escolha real).
     * ship_order confirma de fato o método.
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        $carrier = $this->resolveShippingCarrier($order);

        $params = $this->client->get('/api/v2/logistics/get_shipping_parameter', [
            'order_sn' => $order->external_order_id,
        ]);

        $branchId = $params['response']['info_needed']['dropoff']['branch_list'][0]['branch_id'] ?? null;

        if (! $branchId) {
            throw new RuntimeException("Pedido {$order->external_order_id}: nenhum branch_id de dropoff disponível na Shopee.");
        }

        $result = $this->client->post('/api/v2/logistics/ship_order', [
            'order_sn' => $order->external_order_id,
            'dropoff' => ['branch_id' => $branchId],
        ]);

        return [
            'external_shipment_id' => $order->external_order_id,
            'shipping_method' => $carrier ?? 'drop_off',
            'status' => empty($result['error']) ? 'confirmed' : 'error',
        ];
    }

    /**
     * Consulta `shipping_carrier` no detalhe do pedido e loga um alerta se
     * não bater com o padrão "Expresso" esperado — ver comentário de
     * EXPRESS_CARRIER_PATTERN. Nunca lança exceção: uma falha aqui (campo
     * ausente, erro de rede) não pode derrubar a confirmação de envio real,
     * só faz a verificação virar "não verificado" em vez de travar o
     * pedido.
     */
    private function resolveShippingCarrier(Order $order): ?string
    {
        try {
            $response = $this->client->get('/api/v2/order/get_order_detail', [
                'order_sn_list' => $order->external_order_id,
                'response_optional_fields' => 'shipping_carrier',
            ]);

            $carrier = $response['response']['order_list'][0]['shipping_carrier'] ?? null;
        } catch (ShopeeException $exception) {
            Log::channel('shopee')->warning('shopee.shipping_carrier.lookup_failed', [
                'order_id' => $order->id,
                'order_sn' => $order->external_order_id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($carrier && ! preg_match(self::EXPRESS_CARRIER_PATTERN, $carrier)) {
            Log::channel('shopee')->warning('shopee.shipping_carrier.not_express', [
                'order_id' => $order->id,
                'order_sn' => $order->external_order_id,
                'carrier' => $carrier,
            ]);
        }

        return $carrier;
    }

    /**
     * 3 chamadas: create_shipping_document cria a tarefa (idempotente por
     * order_sn) → get_shipping_document_result até status=READY
     * (processamento assíncrono do lado da Shopee) → download_shipping_document
     * devolve o binário. `ready: false` enquanto ainda está PROCESSING.
     */
    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        $this->client->post('/api/v2/logistics/create_shipping_document', [
            'order_list' => [['order_sn' => $order->external_order_id]],
        ]);

        $result = $this->client->post('/api/v2/logistics/get_shipping_document_result', [
            'order_list' => [['order_sn' => $order->external_order_id]],
        ]);

        $status = $result['response']['result_list'][0]['status'] ?? null;

        if ($status !== 'READY') {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $label = $this->client->getBinary('/api/v2/logistics/download_shipping_document', [
            'order_list' => [['order_sn' => $order->external_order_id]],
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
        ]);

        return ['ready' => true, 'contents' => $label['contents'], 'content_type' => $label['content_type']];
    }
}
