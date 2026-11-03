<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Notifications\MarketplaceReturnAlertNotification;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Traz devoluções e reclamações do Mercado Livre, da Shopee e do TikTok
 * Shop (este pelo agente do TikTok, ver sincronizarTikTok) pro controle
 * de devoluções (pedido do usuário 2026-10-06) e põe tudo na mesma régua
 * (MarketplaceReturn::SITUACOES). Cada mudança vira evento no histórico, e
 * pendência nova avisa a equipe na sineta uma vez só.
 *
 * Campos conferidos nas APIs reais em 2026-10-06:
 * - ML: post-purchase/v1/claims/{id} (players[].available_actions com
 *   due_date = o que esperam da gente), v2/claims/{id}/returns (status do
 *   envio de volta, rastreio, status_money, refund_at — "shipped" quer
 *   dizer que o comprador é reembolsado já na postagem, antes de chegar).
 * - Shopee: returns/get_return_list (due_date = prazo de resposta,
 *   return_seller_due_date = prazo pra conferir depois que chega) e
 *   get_return_detail (logistics_status do envio de volta).
 */
class ReturnsSyncService
{
    private const ML_MOTIVOS = [
        'not_working_item' => 'Produto não funciona',
        'repentant_buyer' => 'Desistiu da compra',
        'different_item' => 'Produto diferente do anúncio',
        'incomplete_item' => 'Produto incompleto / faltando peças',
        'damaged_item' => 'Chegou danificado',
        'defective_item' => 'Produto com defeito',
        'not_received' => 'Não recebeu o produto',
        'wrong_item' => 'Produto errado',
        'missing_item' => 'Faltando item',
    ];

    private const SHOPEE_MOTIVOS = [
        'FUNCTIONAL_DMG' => 'Produto com defeito',
        'NOT_RECEIPT' => 'Não recebeu o produto',
        'NONRECEIPT' => 'Não recebeu o produto',
        'ITEM_MISSING' => 'Faltando item / peça',
        'PHYSICAL_DMG' => 'Chegou danificado',
        'ITEM_DAMAGED' => 'Chegou danificado',
        'DAMAGED' => 'Chegou danificado',
        'WRONG_ITEM' => 'Produto errado',
        'ITEM_WRONGDAMAGED' => 'Produto errado ou danificado',
        'DIFFERENT_DESCRIPTION' => 'Diferente do anúncio',
        'DIFF_DESC' => 'Diferente do anúncio',
        'MUTUAL_AGREE' => 'Acordo com o comprador',
        'CHANGE_MIND' => 'Desistiu da compra',
        'EXPIRED' => 'Produto vencido',
        'FAKE' => 'Suspeita de falsificação',
        'USED' => 'Produto usado',
    ];

    public function __construct(
        private readonly MercadoLivreClient $mercadoLivre,
        private readonly ShopeeClient $shopee,
    ) {}

    /** @return array{mercado_livre: int, shopee: int} */
    public function sincronizar(): array
    {
        return [
            'mercado_livre' => $this->sincronizarMercadoLivre(),
            'shopee' => $this->sincronizarShopee(),
        ];
    }

    // ---- Mercado Livre ---------------------------------------------------

    public function sincronizarMercadoLivre(): int
    {
        $ids = collect();

        try {
            $offset = 0;

            do {
                $pagina = $this->mercadoLivre->get('post-purchase/v1/claims/search', ['status' => 'opened', 'limit' => 50, 'offset' => $offset]);
                $ids = $ids->merge(collect($pagina['data'] ?? [])->pluck('id'));
                $offset += 50;
            } while ($offset < (int) ($pagina['paging']['total'] ?? 0) && $offset < 500);
        } catch (Throwable $exception) {
            Log::warning('devolucoes.ml.busca_falhou', ['erro' => $exception->getMessage()]);
        }

        // Fechadas recentes: as que já acompanhamos (pra ver o desfecho) e
        // as que o webhook gravou nos últimos 90 dias (carga inicial).
        $ids = $ids
            ->merge(MarketplaceReturn::query()->where('channel', Order::ORIGIN_MERCADO_LIVRE)->where('manual', false)
                ->where(fn ($q) => $q->whereIn('situacao', MarketplaceReturn::EM_ABERTO)->orWhere('updated_at', '>=', now()->subDays(15)))
                ->pluck('external_id'))
            ->merge(MarketplaceClaim::query()->where('channel', Order::ORIGIN_MERCADO_LIVRE)
                ->whereIn('type', ['returns', 'mediations'])
                ->where('created_at', '>=', now()->subDays(90))
                ->whereNotIn('external_claim_id', MarketplaceReturn::query()->where('channel', Order::ORIGIN_MERCADO_LIVRE)->select('external_id'))
                ->pluck('external_claim_id'))
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique();

        $total = 0;

        foreach ($ids as $id) {
            try {
                if ($this->sincronizarClaimMercadoLivre($id)) {
                    $total++;
                }
            } catch (Throwable $exception) {
                Log::warning('devolucoes.ml.claim_falhou', ['claim' => $id, 'erro' => $exception->getMessage()]);
            }

            usleep(150000);
        }

        return $total;
    }

    public function sincronizarClaimMercadoLivre(string $claimId): ?MarketplaceReturn
    {
        $claim = $this->mercadoLivre->get("post-purchase/v1/claims/{$claimId}");

        if (! in_array($claim['type'] ?? null, ['returns', 'mediations'], true)) {
            return null;
        }

        try {
            $devolucao = $this->mercadoLivre->get("post-purchase/v2/claims/{$claimId}/returns");
        } catch (Throwable) {
            // 429 ou falha passageira não apaga o que já se sabia: gravar vazio
            // fazia o status voltar na varredura seguinte como se fosse novo, e
            // o histórico repetia "Dinheiro" e "Envio de volta" sem parar.
            $devolucao = MarketplaceReturn::query()
                ->where('channel', Order::ORIGIN_MERCADO_LIVRE)->where('external_id', $claimId)
                ->first()?->raw_payload['return'] ?? null;
        }

        $envio = collect($devolucao['shipments'] ?? [])->firstWhere('type', 'return') ?? ($devolucao['shipments'][0] ?? null);
        $statusEnvio = $this->normalizarEnvioMercadoLivre($devolucao['status'] ?? null);
        $acoes = collect($claim['players'] ?? [])->firstWhere('role', 'respondent')['available_actions'] ?? [];
        $prazo = collect($acoes)->pluck('due_date')->filter()->map(fn ($d) => Carbon::parse($d))->sort()->first();
        $aberta = ($claim['status'] ?? null) === 'opened';

        $situacao = match (true) {
            ! $aberta && $statusEnvio === 'delivered' => MarketplaceReturn::ENTREGUE,
            ! $aberta => MarketplaceReturn::ENCERRADA,
            $acoes !== [] => MarketplaceReturn::AGUARDANDO_RESPOSTA,
            $statusEnvio === 'pending' => MarketplaceReturn::AGUARDANDO_ENVIO,
            $statusEnvio === 'shipped' => MarketplaceReturn::EM_TRANSITO,
            $statusEnvio === 'delivered' => MarketplaceReturn::ENTREGUE,
            ($claim['stage'] ?? null) === 'dispute' => MarketplaceReturn::EM_MEDIACAO,
            default => MarketplaceReturn::EM_MEDIACAO,
        };

        $resolucao = $claim['resolution'] ?? null;
        $pedidoExterno = ($claim['resource'] ?? null) === 'order' ? (string) $claim['resource_id'] : null;

        return $this->gravar(Order::ORIGIN_MERCADO_LIVRE, $claimId, [
            'external_order_id' => $pedidoExterno,
            'kind' => ($claim['type'] === 'returns' || $devolucao) ? MarketplaceReturn::KIND_DEVOLUCAO : MarketplaceReturn::KIND_RECLAMACAO,
            'reason_code' => $claim['reason_id'] ?? null,
            'reason_label' => $this->motivoMercadoLivre($claim['reason_id'] ?? null),
            'situacao' => $situacao,
            'platform_status' => trim(($claim['status'] ?? '').' / '.($claim['stage'] ?? ''), ' /'),
            'return_status' => $statusEnvio,
            'money_status' => $devolucao['status_money'] ?? null,
            'refund_at' => $devolucao['refund_at'] ?? null,
            'tracking_number' => $envio['tracking_number'] ?? null,
            'respond_due_at' => $situacao === MarketplaceReturn::AGUARDANDO_RESPOSTA ? $prazo : null,
            'opened_at' => isset($claim['date_created']) ? Carbon::parse($claim['date_created']) : null,
            'closed_at' => ! $aberta && isset($resolucao['date_created']) ? Carbon::parse($resolucao['date_created']) : null,
            'resolution' => $resolucao ? $this->resolucaoMercadoLivre($resolucao) : null,
            'raw_payload' => ['claim' => $claim, 'return' => $devolucao],
        ], $statusEnvio === 'delivered');
    }

    private function normalizarEnvioMercadoLivre(?string $status): ?string
    {
        return match ($status) {
            null => null,
            'pending', 'label_generated', 'ready_to_ship' => 'pending',
            'shipped' => 'shipped',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            default => 'not_delivered',
        };
    }

    private function motivoMercadoLivre(?string $codigo): ?string
    {
        if (! $codigo) {
            return null;
        }

        return Cache::rememberForever("devolucoes.ml.motivo.{$codigo}", function () use ($codigo) {
            try {
                $motivo = $this->mercadoLivre->get("post-purchase/v1/claims/reasons/{$codigo}");

                return self::ML_MOTIVOS[$motivo['name'] ?? ''] ?? ($motivo['detail'] ?? $codigo);
            } catch (Throwable) {
                return $codigo;
            }
        });
    }

    private const ML_DESFECHOS = [
        'item_returned' => 'Produto devolvido',
        'payment_refunded' => 'Reembolsado',
        'partial_refunded' => 'Reembolso parcial',
        'refund_without_return' => 'Reembolso sem devolução',
        'warehouse_decision' => 'Decisão do armazém do ML',
        'already_shipped' => 'Já tinha sido enviado',
        'prefered_to_keep_product' => 'Comprador ficou com o produto',
        'item_changed' => 'Produto trocado',
        'not_delivered' => 'Não entregue',
        'buyer_cancelled' => 'Comprador desistiu da reclamação',
        'expired' => 'Prazo expirou',
        'seller_explained_functions' => 'Vendedor explicou o funcionamento',
        'respondent_timeout' => 'Vendedor não respondeu a tempo',
        'timeout' => 'Prazo expirou',
    ];

    private function resolucaoMercadoLivre(array $resolucao): string
    {
        $favor = in_array('respondent', $resolucao['benefited'] ?? [], true) ? 'a nosso favor' : 'a favor do comprador';
        $motivo = (string) ($resolucao['reason'] ?? 'encerrada');

        return (self::ML_DESFECHOS[$motivo] ?? ucfirst(str_replace('_', ' ', $motivo))).' — '.$favor;
    }

    // ---- Shopee ---------------------------------------------------------

    public function sincronizarShopee(): int
    {
        $total = 0;
        $ate = now();

        // A API aceita janelas curtas — 6 de 15 dias cobrem 90 dias.
        for ($janela = 0; $janela < 6; $janela++) {
            $fim = $ate->copy()->subDays(15 * $janela);
            $inicio = $fim->copy()->subDays(15);
            $pagina = 0;

            do {
                try {
                    $resposta = $this->shopee->get('/api/v2/returns/get_return_list', [
                        'page_no' => $pagina, 'page_size' => 50,
                        'create_time_from' => $inicio->timestamp, 'create_time_to' => $fim->timestamp,
                    ])['response'] ?? [];
                } catch (Throwable $exception) {
                    Log::warning('devolucoes.shopee.lista_falhou', ['erro' => $exception->getMessage()]);

                    break;
                }

                foreach ($resposta['return'] ?? [] as $devolucao) {
                    try {
                        if ($this->gravarShopee($devolucao)) {
                            $total++;
                        }
                    } catch (Throwable $exception) {
                        Log::warning('devolucoes.shopee.devolucao_falhou', ['return_sn' => $devolucao['return_sn'] ?? null, 'erro' => $exception->getMessage()]);
                    }
                }

                $pagina++;
            } while (($resposta['more'] ?? false) && $pagina < 20);
        }

        return $total;
    }

    private function gravarShopee(array $devolucao): ?MarketplaceReturn
    {
        $sn = (string) ($devolucao['return_sn'] ?? '');
        $status = (string) ($devolucao['status'] ?? '');

        if ($sn === '') {
            return null;
        }

        $existente = MarketplaceReturn::query()->where('channel', Order::ORIGIN_SHOPEE)->where('external_id', $sn)->first();
        $final = in_array($status, ['CANCELLED', 'CLOSED', 'REFUND_PAID'], true);

        // O status do envio de volta só vem no detalhe — e só precisa ser
        // consultado enquanto o caso não acabou.
        $logistica = $existente?->raw_payload['logistics_status'] ?? null;

        if (! $final || ! $existente) {
            try {
                $logistica = $this->shopee->get('/api/v2/returns/get_return_detail', ['return_sn' => $sn])['response']['logistics_status'] ?? null;
            } catch (Throwable) {
                // segue com o que já tinha
            }
        }

        $statusEnvio = match (true) {
            $logistica === null => null,
            str_contains($logistica, 'DELIVERY_DONE') => 'delivered',
            str_contains($logistica, 'PENDING') || str_contains($logistica, 'READY') || str_contains($logistica, 'REQUEST_CREATED') => 'pending',
            str_contains($logistica, 'FAIL') || str_contains($logistica, 'LOST') || str_contains($logistica, 'CANCEL') => 'not_delivered',
            default => 'shipped',
        };

        $situacao = match (true) {
            $status === 'CANCELLED' => MarketplaceReturn::CANCELADA,
            in_array($status, ['CLOSED', 'REFUND_PAID'], true) && $statusEnvio === 'delivered' => MarketplaceReturn::ENTREGUE,
            in_array($status, ['CLOSED', 'REFUND_PAID'], true) => MarketplaceReturn::ENCERRADA,
            $status === 'REQUESTED' => MarketplaceReturn::AGUARDANDO_RESPOSTA,
            in_array($status, ['JUDGING', 'SELLER_DISPUTE'], true) => MarketplaceReturn::EM_MEDIACAO,
            $statusEnvio === 'delivered' => MarketplaceReturn::ENTREGUE,
            $statusEnvio === 'shipped' => MarketplaceReturn::EM_TRANSITO,
            default => MarketplaceReturn::AGUARDANDO_ENVIO,
        };

        $quando = fn ($ts) => (int) $ts > 0 ? Carbon::createFromTimestamp((int) $ts) : null;

        return $this->gravar(Order::ORIGIN_SHOPEE, $sn, [
            'external_order_id' => $devolucao['order_sn'] ?? null,
            'kind' => MarketplaceReturn::KIND_DEVOLUCAO,
            'reason_code' => $devolucao['reason'] ?? null,
            'reason_label' => self::SHOPEE_MOTIVOS[$devolucao['reason'] ?? ''] ?? ($devolucao['reason'] ?? null),
            'situacao' => $situacao,
            'platform_status' => $status,
            'return_status' => $statusEnvio,
            'money_status' => $status === 'REFUND_PAID' ? 'refunded' : null,
            'refund_amount' => $devolucao['refund_amount'] ?? null,
            'tracking_number' => ($devolucao['tracking_number'] ?? '') ?: null,
            'respond_due_at' => $situacao === MarketplaceReturn::AGUARDANDO_RESPOSTA ? $quando($devolucao['due_date'] ?? 0) : null,
            'receive_due_at' => $quando($devolucao['return_seller_due_date'] ?? 0),
            'opened_at' => $quando($devolucao['create_time'] ?? 0),
            'closed_at' => in_array($status, ['CLOSED', 'REFUND_PAID', 'CANCELLED'], true) ? $quando($devolucao['update_time'] ?? 0) : null,
            'raw_payload' => [...$devolucao, 'logistics_status' => $logistica],
        ], $statusEnvio === 'delivered');
    }

    // ---- TikTok Shop ----------------------------------------------------

    /**
     * Devoluções do TikTok Shop (pedido do Lira 03/11/2026). O app da loja no
     * Partner Center não está ligado, então o agente do TikTok (Chrome logado
     * na Cora Box) lê a tela Pedidos › Devoluções do seller-br e manda os
     * cartões crus como vieram (POST /api/tiktok/devolucoes). A leitura fica
     * aqui para mudar sem mexer no outro PC.
     *
     * Cartão conferido em 03/11 (api/v1/reverse/component/orders/list):
     * biz_data traz pedido e ID da devolução; os blocos trazem tipo, motivo,
     * observação do cliente, status, envio de volta e valores; o
     * product_detail_card traz produto, cor e SKU.
     *
     * @param  list<array<string, mixed>>  $cartoes
     */
    public function sincronizarTikTok(array $cartoes): int
    {
        $total = 0;

        foreach ($cartoes as $cartao) {
            try {
                if (is_array($cartao) && $this->gravarTikTok($cartao)) {
                    $total++;
                }
            } catch (Throwable $exception) {
                Log::warning('devolucoes.tiktok.cartao_falhou', ['devolucao' => $cartao['biz_data']['reverse_main_order_id'] ?? null, 'erro' => $exception->getMessage()]);
            }
        }

        return $total;
    }

    public function gravarTikTok(array $cartao): ?MarketplaceReturn
    {
        $id = (string) ($cartao['biz_data']['reverse_main_order_id'] ?? '');
        $pedido = (string) ($cartao['biz_data']['main_order_id'] ?? '');

        if ($id === '' || $pedido === '') {
            return null;
        }

        $campos = [];
        $this->camposTikTok($cartao['card'] ?? [], $campos);
        $campo = fn (string $nome) => $campos[$nome][0] ?? null;

        $status = trim((string) $campo('status_description'));
        $envioTexto = mb_strtolower(trim((string) $campo('fulfillment_return_button')));
        $botoes = collect($campos['button_list'] ?? [])->map(fn ($b) => mb_strtolower((string) $b));
        $aceitarPacote = $botoes->contains(fn ($b) => str_contains($b, 'aceitar pacote'));

        $statusEnvio = match (true) {
            $envioTexto === '' => null,
            str_contains($envioTexto, 'entregue') => 'delivered',
            str_contains($envioTexto, 'trânsito') || str_contains($envioTexto, 'transito') || str_contains($envioTexto, 'postado') => 'shipped',
            str_contains($envioTexto, 'cancel') || str_contains($envioTexto, 'falh') || str_contains($envioTexto, 'devolvido ao') => 'not_delivered',
            default => 'shipped',
        };

        $s = mb_strtolower($status);
        $situacao = match (true) {
            str_contains($s, 'cancelad') => MarketplaceReturn::CANCELADA,
            str_contains($s, 'recurso') || str_contains($s, 'contest') || str_contains($s, 'disputa') || str_contains($s, 'em análise') => MarketplaceReturn::EM_MEDIACAO,
            str_contains($s, 'aguardando devolução') => MarketplaceReturn::AGUARDANDO_ENVIO,
            str_contains($s, 'aguardando sua') || str_contains($s, 'aguardando a sua') || str_contains($s, 'aguardando o vendedor') || str_contains($s, 'aguardando resposta do vendedor') => MarketplaceReturn::AGUARDANDO_RESPOSTA,
            $statusEnvio === 'delivered' && $aceitarPacote => MarketplaceReturn::ENTREGUE,
            $statusEnvio === 'shipped' => MarketplaceReturn::EM_TRANSITO,
            str_contains($s, 'encerrad') || str_contains($s, 'concluíd') || str_contains($s, 'aprovada automaticamente') => MarketplaceReturn::ENCERRADA,
            $statusEnvio === 'delivered' => MarketplaceReturn::ENCERRADA,
            default => MarketplaceReturn::EM_MEDIACAO,
        };

        $tipo = (int) ($cartao['biz_data']['reverseType'] ?? 0);
        $tipoTexto = (string) $campo('reason_reverse_type');
        $semDevolverProduto = $tipo === 2 || str_contains(mb_strtolower($tipoTexto), 'apenas reembolso');

        // Data vem em segundos; o ID da devolução (19 dígitos) também é número
        // e não pode passar por data.
        $ehData = fn ($v) => is_numeric($v) && (float) $v > 1_500_000_000 && (float) $v < 4_000_000_000;
        $quando = fn ($ts) => $ehData($ts) ? Carbon::createFromTimestamp((int) $ts, config('app.timezone')) : null;
        $momentos = collect([...($campos['status_detail_element'] ?? []), ...($campos['amount_refund_time'] ?? []), ...($campos['fulfillment_return_time'] ?? [])])
            ->filter($ehData)->map(fn ($v) => (int) $v);
        $encerrada = in_array($situacao, [MarketplaceReturn::ENCERRADA, MarketplaceReturn::CANCELADA], true);
        $reembolsado = str_contains($s, 'reembolso concluído') || str_contains($s, 'aprovada automaticamente');

        $produtos = collect($cartao['linked_cards'] ?? [])->firstWhere('name', 'product_detail_card');
        $skus = [];
        $this->skusTikTok($produtos ?? [], $skus);

        return $this->gravar(Order::ORIGIN_TIKTOK_SHOP, $id, [
            'external_order_id' => $pedido,
            'kind' => $semDevolverProduto ? MarketplaceReturn::KIND_RECLAMACAO : MarketplaceReturn::KIND_DEVOLUCAO,
            'reason_label' => $campo('reason_name') ?: $campo('reason'),
            'product_label' => $skus !== [] ? mb_substr(implode(', ', $skus), 0, 500) : null,
            'buyer_note' => trim((string) ($campo('reason_note') ?: $campo('customers_remarks'))) ?: null,
            'situacao' => $situacao,
            'platform_status' => mb_substr(trim($tipoTexto.' / '.$status, ' /'), 0, 64),
            'return_status' => $semDevolverProduto ? null : ($statusEnvio ?? ($situacao === MarketplaceReturn::AGUARDANDO_ENVIO ? 'pending' : null)),
            'money_status' => $reembolsado ? 'refunded' : null,
            'refund_amount' => $this->valorTikTok($campo('amount_refund') ?? ($cartao['biz_data']['return_price'] ?? null)),
            'opened_at' => $quando($campo('header_right_time')),
            'closed_at' => $encerrada && $momentos->isNotEmpty() ? $quando($momentos->max()) : null,
            'raw_payload' => [
                'biz_data' => $cartao['biz_data'] ?? null,
                'tipo' => $tipoTexto,
                'status' => $status,
                'envio' => $envioTexto ?: null,
                'botoes' => $botoes->values()->all(),
                'produtos' => $skus,
                'historico' => $campos['status_detail_element'] ?? [],
            ],
        ], $statusEnvio === 'delivered');
    }

    /** Junta nome => textos de cada elemento do cartão (texto, par título/valor, botão). */
    private function camposTikTok(mixed $no, array &$campos): void
    {
        if (! is_array($no)) {
            return;
        }

        if (isset($no['name']) && is_string($no['name'])) {
            $valor = match (true) {
                isset($no['text_pair']) => $this->textoTikTok($no['text_pair']['content'] ?? null),
                isset($no['text']) => $this->textoTikTok($no['text']),
                isset($no['button']) => $this->textoTikTok($no['button']['text'] ?? null),
                default => null,
            };

            if ($valor !== null && $valor !== '') {
                $campos[$no['name']][] = $valor;
            }
        }

        foreach ($no as $filho) {
            $this->camposTikTok($filho, $campos);
        }
    }

    private function textoTikTok(mixed $texto): ?string
    {
        if (! is_array($texto)) {
            return null;
        }

        if (isset($texto['content']) && is_string($texto['content'])) {
            return trim($texto['content']);
        }

        $partes = [];
        foreach ($texto['dynamic_express']['items'] ?? [] as $item) {
            $frase = (string) ($item['message_content'] ?? '');
            foreach ($item['params'] ?? [] as $chave => $param) {
                $kvs = $param['kvs'] ?? [];
                $valor = $chave === 'copy' ? '' : (string) ($kvs['title'] ?? $kvs['content'] ?? $kvs['target_timestamp'] ?? '');
                $frase = str_replace('{{'.$chave.'}}', $valor, $frase);
            }
            $partes[] = $frase;
        }

        return $partes === [] ? null : trim(implode(' ', $partes));
    }

    private function skusTikTok(mixed $no, array &$skus): void
    {
        if (! is_array($no)) {
            return;
        }

        if (isset($no['product_name']) && is_string($no['product_name'])) {
            $cor = trim((string) ($no['sku_name'] ?? ''));
            $sku = trim((string) ($no['seller_sku'] ?? ''));
            $skus[] = ((int) ($no['quantity'] ?? 1)).'x '.trim($no['product_name']).($cor !== '' ? " ({$cor})" : '').($sku !== '' ? " · SKU {$sku}" : '');

            return;
        }

        foreach ($no as $filho) {
            $this->skusTikTok($filho, $skus);
        }
    }

    private function valorTikTok(mixed $texto): ?float
    {
        if (! is_string($texto) || ! preg_match('/(\d{1,3}(?:\.\d{3})*,\d{2})/', $texto, $m)) {
            return null;
        }

        return (float) str_replace(['.', ','], ['', '.'], $m[1]);
    }

    // ---- Gravação comum -------------------------------------------------

    /**
     * Grava o estado novo, registra no histórico o que mudou e avisa a
     * equipe das pendências novas. O que a equipe fez (recebimento,
     * veredito) nunca é sobrescrito pela plataforma.
     */
    private function gravar(string $canal, string $externo, array $dados, bool $entregue): MarketplaceReturn
    {
        $registro = MarketplaceReturn::query()->firstOrNew(['channel' => $canal, 'external_id' => $externo]);
        $novo = ! $registro->exists;
        $antes = $registro->only(['situacao', 'return_status', 'money_status', 'platform_status']);

        // Depois que a equipe conferiu, a situação fica "conferida" — a
        // plataforma pode seguir mudando o próprio status por baixo.
        if ($registro->verdict) {
            $dados['situacao'] = MarketplaceReturn::CONFERIDA;
        }

        if ($entregue && ! $registro->delivered_at) {
            $dados['delivered_at'] = now();
        }

        if (! $registro->order_id && ! empty($dados['external_order_id'])) {
            $dados['order_id'] = Order::query()->where('origin', $canal)->where('external_order_id', $dados['external_order_id'])->value('id');
        }

        $registro->fill([...$dados, 'last_synced_at' => now()])->save();

        $mudancas = [];

        if ($novo) {
            $mudancas[] = 'Aberta na plataforma'.($registro->reason_label ? ": {$registro->reason_label}" : '');
        }

        if (! $novo && $antes['situacao'] !== $registro->situacao) {
            $mudancas[] = MarketplaceReturn::SITUACOES[$registro->situacao] ?? $registro->situacao;
        }

        if ($antes['return_status'] !== $registro->return_status && $registro->return_status) {
            $mudancas[] = 'Envio de volta: '.match ($registro->return_status) {
                'pending' => 'aguardando o comprador postar',
                'shipped' => 'postado / a caminho',
                'delivered' => 'entregue',
                'not_delivered' => 'não entregue',
                'cancelled' => 'cancelado',
                default => $registro->return_status,
            };
        }

        if ($antes['money_status'] !== $registro->money_status && $registro->money_status) {
            $mudancas[] = 'Dinheiro: '.match ($registro->money_status) {
                'retained' => 'retido pela plataforma',
                'refunded' => 'devolvido ao comprador',
                'available' => 'liberado pra loja',
                default => $registro->money_status,
            };
        }

        foreach ($mudancas as $texto) {
            $registro->events()->create(['situacao' => $registro->situacao, 'description' => $texto, 'happened_at' => now()]);
        }

        $this->avisar($registro, $novo);

        return $registro;
    }

    /** Uma notificação por pendência, uma vez só (alerts_notified). */
    public function avisar(MarketplaceReturn $registro, bool $novo = false): void
    {
        $avisados = $registro->alerts_notified ?? [];
        $avisos = [];

        // Carga inicial: caso antigo já encerrado não vira enxurrada de aviso.
        $recente = ! $registro->opened_at || $registro->opened_at->gte(now()->subDays(30));

        if ($novo && $recente && in_array($registro->situacao, MarketplaceReturn::EM_ABERTO, true)) {
            $avisos[] = 'Nova '.($registro->kind === MarketplaceReturn::KIND_RECLAMACAO ? 'reclamação' : 'devolução').' aberta';
            $avisados[] = 'aberta';
        }

        foreach ($registro->alertas() as $alerta) {
            if (in_array($alerta['chave'], ['mediacao', 'sem_resposta'], true) || in_array($alerta['chave'], $avisados, true) || ! $recente) {
                continue;
            }

            $avisos[] = $alerta['texto'];
            $avisados[] = $alerta['chave'];
        }

        if ($avisos === []) {
            return;
        }

        $registro->forceFill(['alerts_notified' => array_values(array_unique($avisados))])->saveQuietly();

        $equipe = User::query()->where('role', User::ROLE_ADMIN)->get();

        foreach (array_unique($avisos) as $aviso) {
            Notification::send($equipe, new MarketplaceReturnAlertNotification($registro, $aviso));
        }
    }
}
