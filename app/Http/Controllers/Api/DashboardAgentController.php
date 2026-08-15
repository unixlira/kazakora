<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Content\Models\DailyText;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\OrderImageArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Dados agregados pro dashboard do agente local (app nativo Windows que
 * substitui o print-agent Node.js) — mesma autenticação por token fixo do
 * PrintAgentController (ver AuthenticatePrintAgent), mesmo trust boundary,
 * já que é o mesmo processo/máquina fazendo os dois papéis.
 */
class DashboardAgentController extends Controller
{
    /**
     * "Vendas confirmadas" — mesma definição já usada em
     * Modules\Admin\Http\Controllers\DashboardController::PAID_STATUSES,
     * reaproveitada aqui de propósito pra não haver duas definições
     * divergentes de "faturamento" no sistema.
     */
    private const PAID_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    private const CHANNELS = [
        Order::ORIGIN_STORE,
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
        Order::ORIGIN_TIKTOK_SHOP,
        Order::ORIGIN_AMAZON,
        Order::ORIGIN_SHEIN,
    ];

    public function channels(): JsonResponse
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();

        $accounts = MarketplaceAccount::query()->get()->keyBy('channel');

        $lastOrders = Order::query()
            ->selectRaw('origin, MAX(id) as last_order_id')
            ->groupBy('origin')
            ->pluck('last_order_id', 'origin');

        // selectRaw()+MAX() devolve string crua no formato do MySQL
        // ("2026-08-03 19:44:42"), não um Carbon — sem esse parse, o JSON
        // sai sem o "T"/offset ISO 8601 que o DateTimeOffset do C# exige,
        // e o KoraSync quebra ao desserializar (achado ao vivo 2026-08-04,
        // reproduzido rodando o parser real do cliente contra essa resposta
        // — a exceção derrubava o tick inteiro, inclusive a busca de
        // etiquetas, que roda depois dessa no mesmo ciclo).
        $lastPrintedJobs = PrintJob::query()
            ->join('orders', 'orders.id', '=', 'print_jobs.order_id')
            ->where('print_jobs.status', PrintJob::STATUS_PRINTED)
            ->selectRaw('orders.origin, MAX(print_jobs.printed_at) as last_printed_at')
            ->groupBy('orders.origin')
            ->pluck('last_printed_at', 'orders.origin')
            ->map(fn ($value) => $value ? \Carbon\Carbon::parse($value) : null);

        $printedTodayByChannel = PrintJob::query()
            ->join('orders', 'orders.id', '=', 'print_jobs.order_id')
            ->where('print_jobs.status', PrintJob::STATUS_PRINTED)
            ->where('print_jobs.printed_at', '>=', $today)
            ->selectRaw('orders.origin, COUNT(*) as total')
            ->groupBy('orders.origin')
            ->pluck('total', 'orders.origin');

        // Uma query agrupada por métrica em vez de uma por canal — 6 canais
        // × várias métricas em query separada viraria N+1 real, pesado pra
        // um endpoint que o KoraSync consulta a cada poucos segundos.
        $revenueMonthByChannel = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereIn('status', self::PAID_STATUSES)
            ->selectRaw('origin, SUM(total) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $revenueTodayByChannel = Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->selectRaw('origin, SUM(total) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $salesMonthByChannel = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereIn('status', self::PAID_STATUSES)
            ->selectRaw('origin, COUNT(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $ordersTodayByChannel = Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->selectRaw('origin, COUNT(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        // Achado real 2026-08-07 ("card de devolução não está ok, tem 1 no
        // meli"): essa definição só olhava Payment estornado (Stripe/Mercado
        // Pago) — pedido importado de canal externo NUNCA tem Payment local
        // (paga por fora, na própria Shopee/ML), então uma devolução/
        // reclamação real no Mercado Livre (MarketplaceClaim, já rastreado
        // de verdade em /admin/integracoes/mercado-livre/devolucoes) nunca
        // batia aqui. Une as duas fontes reais de "devolução" que o sistema
        // tem — pagamento estornado (loja própria) OU claim do canal
        // (marketplace) — em vez de só uma.
        $returnsMonthByChannel = Order::query()
            ->where(function ($query) use ($monthStart) {
                $query->whereHas('payments', function ($query) use ($monthStart) {
                    $query->where('status', Payment::STATUS_REFUNDED)
                        ->where('updated_at', '>=', $monthStart);
                })->orWhereHas('marketplaceClaims', function ($query) use ($monthStart) {
                    $query->where('claim_created_at', '>=', $monthStart);
                });
            })
            ->selectRaw('origin, COUNT(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $channels = collect(self::CHANNELS)->map(function (string $channel) use (
            $accounts, $lastOrders, $lastPrintedJobs, $printedTodayByChannel,
            $revenueMonthByChannel, $revenueTodayByChannel, $salesMonthByChannel,
            $ordersTodayByChannel, $returnsMonthByChannel,
        ) {
            $lastOrderId = $lastOrders->get($channel);
            $lastOrder = $lastOrderId ? Order::query()->find($lastOrderId, ['id', 'external_order_id', 'created_at']) : null;

            return [
                'channel' => $channel,
                // A loja própria não é uma MarketplaceAccount — está sempre "conectada".
                'connected' => $channel === Order::ORIGIN_STORE
                    ? true
                    : (bool) ($accounts->get($channel)?->isConnected() ?? false),
                'last_order' => $lastOrder ? [
                    'id' => $lastOrder->id,
                    'external_order_id' => $lastOrder->external_order_id,
                    'created_at' => $lastOrder->created_at,
                ] : null,
                'last_label_printed_at' => $lastPrintedJobs->get($channel),
                'labels_printed_today' => (int) ($printedTodayByChannel->get($channel) ?? 0),
                'revenue_month' => (float) ($revenueMonthByChannel->get($channel) ?? 0),
                'revenue_today' => (float) ($revenueTodayByChannel->get($channel) ?? 0),
                'sales_month' => (int) ($salesMonthByChannel->get($channel) ?? 0),
                'orders_today' => (int) ($ordersTodayByChannel->get($channel) ?? 0),
                'returns_month' => (int) ($returnsMonthByChannel->get($channel) ?? 0),
            ];
        });

        return response()->json(['channels' => $channels]);
    }

    public function metrics(): JsonResponse
    {
        $now = now();
        $today = $now->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();
        $monthStart = $now->copy()->startOfMonth();
        // Comparação justa "mês até agora" vs "mesmo trecho do mês
        // anterior" (não o mês anterior inteiro, que sempre pareceria maior
        // só por ter mais dias já fechados).
        $prevMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $prevMonthToDate = $prevMonthStart->copy()->addDays($now->day - 1)->endOfDay();

        // BUG REAL 2026-08-15 (achado investigando reclamação real do
        // usuário — pedido #305 Shopee: R$44,99 no Seller Center, R$58,24
        // aqui no dashboard do KoraSync): 'total' = subtotal + frete
        // (shipping_cost) — correto pro VALOR DA NOTA FISCAL (SEFAZ exige,
        // ver ShopeeDriver::importOrder()), mas o frete pago pelo
        // comprador/Shopee ao transportador nunca é receita do vendedor.
        // 'subtotal' é o valor real dos produtos, o mesmo que aparece no
        // Seller Center do canal — ver comentário completo em
        // FinancialDashboardController::index().
        $revenueToday = (float) Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('subtotal');

        $revenueYesterday = (float) Order::query()
            ->whereBetween('created_at', [$yesterday, $today])
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('subtotal');

        $revenueMonth = (float) Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('subtotal');

        $revenueMonthPrev = (float) Order::query()
            ->whereBetween('created_at', [$prevMonthStart, $prevMonthToDate])
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('subtotal');

        $salesToday = Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->count();

        $salesYesterday = Order::query()
            ->whereBetween('created_at', [$yesterday, $today])
            ->whereIn('status', self::PAID_STATUSES)
            ->count();

        $cancelledToday = Order::query()
            ->where('status', Order::STATUS_CANCELLED)
            ->where('updated_at', '>=', $today)
            ->count();

        // Achado real 2026-08-07 — ver comentário equivalente em
        // channels(): "devolução"/"reembolso" precisa contar tanto Payment
        // estornado (loja própria) quanto MarketplaceClaim (canal externo,
        // ex: Mercado Livre) — só Payment nunca pegava devolução real de
        // marketplace, já que esses pedidos não têm Payment local nenhum.
        $refundedToday = Order::query()
            ->where(function ($query) use ($today) {
                $query->whereHas('payments', function ($query) use ($today) {
                    $query->where('status', Payment::STATUS_REFUNDED)
                        ->where('updated_at', '>=', $today);
                })->orWhereHas('marketplaceClaims', function ($query) use ($today) {
                    $query->where('claim_created_at', '>=', $today);
                });
            })
            ->count();

        $returnsMonth = Order::query()
            ->where(function ($query) use ($monthStart) {
                $query->whereHas('payments', function ($query) use ($monthStart) {
                    $query->where('status', Payment::STATUS_REFUNDED)
                        ->where('updated_at', '>=', $monthStart);
                })->orWhereHas('marketplaceClaims', function ($query) use ($monthStart) {
                    $query->where('claim_created_at', '>=', $monthStart);
                });
            })
            ->count();

        // Carrinhos ativos: CartSnapshot já é filtrado pra excluir sessões
        // expiradas (mesma janela usada no dashboard admin), e uma linha só
        // existe enquanto o carrinho tem itens — não precisa filtro extra.
        $cartItemsCount = (int) CartSnapshot::query()
            ->where('updated_at', '>=', now()->subMinutes((int) config('session.lifetime')))
            ->sum('items_count');

        // "Lucro líquido" É UMA APROXIMAÇÃO, não lucro real — o sistema não
        // tem custo de produto cadastrado em lugar nenhum (só preço de
        // venda), então isso é só faturamento menos a taxa real do
        // marketplace (hoje só o Mercado Livre tem taxa capturada de
        // verdade — ver OrderChannelFee/MercadoLivreDriver::importOrder()).
        // Pedidos sem taxa capturada (site próprio, Shopee, TikTok Shop)
        // entram no cálculo sem desconto nenhum. Confirmado explicitamente
        // com o usuário como aceitável até custo de produto ser cadastrado.
        $todaysPaidOrderIds = Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->pluck('id');

        $marketplaceFeesToday = (float) OrderChannelFee::query()
            ->whereIn('order_id', $todaysPaidOrderIds)
            ->sum('fee_amount');

        $netProfitToday = $revenueToday - $marketplaceFeesToday;

        return response()->json([
            'revenue_today' => $revenueToday,
            'sales_today' => $salesToday,
            'cancelled_today' => $cancelledToday,
            'refunded_today' => $refundedToday,
            'cart_items_count' => $cartItemsCount,
            'net_profit_today' => $netProfitToday,
            'revenue_month' => $revenueMonth,
            'revenue_month_variation_pct' => $this->variationPct($revenueMonth, $revenueMonthPrev),
            'revenue_today_variation_pct' => $this->variationPct($revenueToday, $revenueYesterday),
            'sales_today_variation_pct' => $this->variationPct($salesToday, $salesYesterday),
            'returns_month' => $returnsMonth,
            'month_label' => $now->translatedFormat('F'),
            'today_label' => $now->format('d/m/Y'),
        ]);
    }

    /**
     * null quando não há base de comparação (ex: mês anterior sem nenhuma
     * venda) — o cliente decide como exibir "sem dado" em vez de receber um
     * 0% ou um Infinity mascarado de 0.
     */
    private function variationPct(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    public function channelOrders(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new NotFoundHttpException("Canal \"{$channel}\" não existe.");
        }

        $orders = Order::query()
            ->where('origin', $channel)
            ->with(['items:id,order_id,product_name,quantity'])
            ->latest('id')
            ->limit(100)
            ->get(['id', 'external_order_id', 'status', 'shipping_name', 'total', 'created_at']);

        $fees = OrderChannelFee::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->get()
            ->keyBy('order_id');

        $shipments = ChannelShipment::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('channel', $channel)
            ->get()
            ->keyBy('order_id');

        $result = $orders->map(function (Order $order) use ($fees, $shipments) {
            $fee = $fees->get($order->id);

            return [
                'id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'status' => $order->status,
                'customer_name' => $order->shipping_name,
                'products' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                ]),
                'gross_amount' => (float) $order->total,
                // null quando o canal ainda não tem integração de taxa real
                // (Shopee/TikTok são stubs hoje) — nunca um valor inventado.
                'fee_amount' => $fee ? (float) $fee->fee_amount : null,
                'net_amount' => $fee ? $fee->netAmount() : null,
                'shipping_method' => $shipments->get($order->id)?->shipping_method,
                'created_at' => $order->created_at,
            ];
        });

        return response()->json(['orders' => $result]);
    }

    /**
     * Listagem read-only pra dashboard (KoraSync) mostrar produto/SKU/pedido
     * por etiqueta — separado de propósito do índice usado pelo próprio
     * agente de impressão (PrintAgentController::index(), só QUEUED, campos
     * mínimos, é o loop de trabalho real). Aqui é histórico recente de
     * qualquer status, só pra exibição.
     */
    public function labels(): JsonResponse
    {
        $jobs = PrintJob::query()
            // Etiqueta de agradecimento (gerada junto com toda etiqueta
            // manual, ver ManualLabelController) não é um dado operacional
            // de pedido — não deve aparecer nessa lista, mesmo filtro já
            // usado na listagem de Etiquetas Manuais.
            ->where('is_thank_you', false)
            ->with(['order:id,external_order_id,origin', 'order.items:id,order_id,product_id,product_name,quantity', 'order.items.product:id,sku'])
            // COALESCE(printed_at, created_at): a última IMPRESSA de verdade
            // fica sempre primeiro (pedido explícito 2026-08-04), não só a
            // mais recentemente criada — um job criado antes mas impresso
            // depois de outro (ex: reentrou na fila por retry) sobe pro
            // topo assim que imprime de verdade. Jobs ainda sem printed_at
            // (queued/claimed/failed) continuam ordenados por created_at.
            ->orderByRaw('COALESCE(printed_at, created_at) DESC')
            ->limit(50)
            ->get(['id', 'order_id', 'channel', 'status', 'error_message', 'created_at', 'printed_at']);

        $result = $jobs->map(fn (PrintJob $job) => [
            'id' => $job->id,
            // Jobs antigos de teste têm PrintJob.channel nulo mesmo com
            // pedido associado — usa o origin do pedido como fallback antes
            // de mostrar "canal desconhecido" à toa.
            'channel' => $job->channel ?? $job->order?->origin,
            'order_id' => $job->order_id,
            'external_order_id' => $job->order?->external_order_id,
            'products' => $job->order?->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'sku' => $item->product?->sku,
            ]) ?? [],
            'status' => $job->status,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at,
            'printed_at' => $job->printed_at,
        ]);

        return response()->json(['labels' => $result]);
    }

    /**
     * Fila de expedição do dia pro KoraSync (app nativo) — pedido explícito
     * 2026-08-06: 2 cards em destaque (mais recente + penúltimo) + lista com
     * scroll pro resto, tudo em ordem decrescente. Mesmo conceito da fila
     * já usada em Modules\Admin\Http\Controllers\PrintJobController::index()
     * (pedido pago, ainda não embalado/enviado), com 1 filtro A MAIS que a
     * versão do admin não tem: só pedidos de HOJE (pedido explícito do
     * usuário pra esse fluxo específico, a versão web mantém todos os
     * pendentes sem esse corte).
     *
     * packed_at (pedido explícito 2026-08-13, revisado no mesmo dia): NÃO
     * tira o pedido da lista — só o card muda de cor/texto pra "Embalado"
     * (ver OrderQueueCardViewModel no KoraSync). Primeira versão desse botão
     * escondia o pedido assim que embalava (whereNull('packed_at') aqui),
     * mas o usuário quer continuar vendo a lista inteira do dia como
     * conferência visual, não perder o pedido de vista assim que aperta o
     * botão — packed_at agora só viaja no payload (campo abaixo) pro app
     * decidir a cor, nunca filtra a query.
     *
     * created_at aqui é a data REAL da venda no canal (placed_at, ver
     * OrderImportService::createOrder()), não a hora que o webhook chegou
     * no nosso servidor — normalizada pro timezone do app em cada driver
     * (MercadoLivreDriver/AmazonDriver/ShopeeDriver) antes de virar
     * created_at, senão o corte "só hoje" abaixo (que compara contra
     * now(), sempre no timezone do app) fica errado perto da virada do
     * dia sempre que o canal manda a data num timezone diferente do nosso
     * (achado real 2026-08-13: Carbon::createFromTimestamp() do
     * ShopeeDriver ficava em UTC, 3h à frente de São Paulo — pedido feito
     * ontem à noite virava "hoje de madrugada" no banco e vazava pra fila
     * de hoje mesmo sem ser de hoje de verdade).
     */
    public function queue(): JsonResponse
    {
        $today = now()->startOfDay();
        $tomorrow = $today->clone()->addDay();

        $orders = Order::query()
            ->where('status', Order::STATUS_PAID)
            ->whereBetween('created_at', [$today, $tomorrow])
            ->with(['items:id,order_id,product_id,product_name,quantity', 'items.product:id,sku'])
            ->withSum('items as units_count', 'quantity')
            // orderByDesc('id') em vez de latest()/created_at: dois pedidos
            // pagos a poucos segundos de distância (ex: 2 vendas quase
            // simultâneas em canais diferentes) podem cair no mesmo
            // segundo de created_at (granularidade do datetime do MySQL),
            // e ORDER BY created_at DESC sozinho não garante desempate —
            // achado real testando este endpoint (o mais antigo dos dois
            // vinha primeiro). id crescente já é uma ordem cronológica
            // exata e sem empate possível.
            ->orderByDesc('id')
            ->get();

        $result = $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'channel' => $order->origin,
            'customer_name' => $order->shipping_name,
            'units_count' => (int) $order->units_count,
            'packed_at' => $order->packed_at,
            'products' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'sku' => $item->product?->sku,
            ]),
            'created_at' => $order->created_at,
        ]);

        return response()->json(['queue' => $result]);
    }

    /**
     * Foto do produto pro card em destaque do KoraSync (pedido explícito
     * 2026-08-15) — a mesma imagem já publicada nos marketplaces (ver
     * OrderImageArchiveService). 404 quando o pedido não tem produto/imagem
     * pra mostrar — esperado (item avulso sem produto local, produto sem
     * foto cadastrada), não é erro; o cliente (KoraSync) trata como "sem
     * imagem", mesmo padrão já usado em GET jobs/{id}/archive.
     *
     * Cache-Control longo: a imagem arquivada nunca muda pro mesmo pedido
     * (archive() é idempotente, sempre a mesma foto), sem motivo pra
     * revalidar a cada poll de 2s do KoraSync.
     */
    public function queueOrderImage(Order $order, OrderImageArchiveService $images): mixed
    {
        $bytes = $images->bytes($order);

        if ($bytes === null) {
            throw new NotFoundHttpException('Pedido sem imagem de produto disponível.');
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Botão "Em preparação" -> "Embalado" do card, no KoraSync — marca o
     * pedido como embalado (packed_at) sem tocar em orders.status, que
     * continua sendo só a visão do canal sobre o pedido (ver comentário da
     * migration 2026_08_13_150000). Idempotente de propósito: reenviar o
     * clique (ex.: duplo clique acidental, ou um retry de rede depois de um
     * timeout que já tinha ido pro servidor) não deve estourar erro, só
     * confirmar o estado já embalado — o operador só precisa saber que o
     * pedido saiu da fila.
     */
    public function packOrder(Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PAID) {
            return response()->json(['message' => 'Pedido não está pago — nada pra embalar.'], 409);
        }

        if ($order->packed_at === null) {
            $order->forceFill(['packed_at' => now()])->save();

            app(OrderFulfillmentTimeline::class)->record(
                $order,
                OrderFulfillmentEvent::STEP_ORDER_PACKED,
                OrderFulfillmentEvent::STATUS_SUCCESS,
                'Pedido marcado como embalado no KoraSync',
            );
        }

        return response()->json(['packed_at' => $order->packed_at]);
    }

    /**
     * Envios AGENDADOS pelo canal (pedido explícito 2026-08-14, achado ao
     * vivo no pedido #278) — venda de Coleta/Places do Mercado Livre em que
     * o próprio canal decidiu só liberar a etiqueta perto de uma data
     * futura (scheduled_for, ver MercadoLivreDriver::extractScheduledFor()
     * e ChannelShippingService::confirm()). Sem essa lista visível, o
     * pedido fica parado em "aguardando etiqueta" que parece exatamente
     * igual a um pedido travado de verdade — ninguém no time consegue
     * distinguir os dois só olhando o KoraSync. Mostra todo envio com
     * scheduled_for preenchido enquanto o CANAL ainda não liberou a
     * etiqueta de verdade (status), mesmo os já vencidos (aí é hora de
     * prestar atenção de verdade: o canal disse que ia liberar e não
     * liberou), ordenado pela data mais próxima primeiro.
     *
     * BUG REAL 2026-08-14, corrigido no mesmo dia: a primeira versão
     * também excluía pedido já embalado (order.packed_at), copiando o
     * filtro de queue() sem pensar — mas embalar (packed_at, botão "Em
     * preparação" do KoraSync) é sobre o operador ter preparado a caixa
     * fisicamente, sem relação nenhuma com o canal ter liberado a
     * etiqueta. Confirmado ao vivo no próprio pedido #278: já estava
     * "embalado" havia horas e a etiqueta continuava tão agendada quanto
     * antes — escondê-lo da lista era exatamente o oposto do que devia
     * acontecer (ainda NÃO PODE sair, mesmo com a caixa pronta).
     */
    public function scheduledShipments(): JsonResponse
    {
        $shipments = ChannelShipment::query()
            ->whereNotNull('scheduled_for')
            ->whereNotIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            ->with(['order:id,external_order_id,origin,shipping_name', 'order.items:id,order_id,product_name,quantity'])
            ->orderBy('scheduled_for')
            ->get()
            ->filter(fn (ChannelShipment $shipment) => $shipment->order !== null)
            ->values();

        $result = $shipments->map(fn (ChannelShipment $shipment) => [
            'order_id' => $shipment->order_id,
            'external_order_id' => $shipment->order->external_order_id,
            'channel' => $shipment->channel,
            'customer_name' => $shipment->order->shipping_name,
            'shipping_method' => $shipment->shipping_method,
            'scheduled_for' => $shipment->scheduled_for,
            // Pra já vir pronto pro KoraSync destacar visualmente quem já
            // passou da data prometida sem liberar — não é o mesmo alerta
            // que "vai liberar em breve".
            'is_overdue' => $shipment->scheduled_for->isPast(),
            'products' => $shipment->order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
            ]),
        ]);

        return response()->json(['scheduled_shipments' => $result]);
    }

    /**
     * Texto diário das Testemunhas de Jeová — só leitura do que já foi
     * salvo pelo comando agendado (App\Console\Commands\FetchDailyText,
     * roda a cada 12h). Não busca ao vivo aqui: esse endpoint precisa
     * responder rápido pro KoraSync, e raspar wol.jw.org na hora da
     * requisição arriscaria travar/atrasar o dashboard por causa de um
     * site externo.
     */
    public function dailyText(): JsonResponse
    {
        $dailyText = DailyText::query()->latest('date')->first();

        if (! $dailyText) {
            return response()->json(['daily_text' => null]);
        }

        return response()->json([
            'daily_text' => [
                'date' => $dailyText->date->toDateString(),
                'weekday_label' => $dailyText->weekday_label,
                'scripture_quote' => $dailyText->scripture_quote,
                'scripture_reference' => $dailyText->scripture_reference,
                'commentary' => $dailyText->commentary,
                'fetched_at' => $dailyText->fetched_at,
            ],
        ]);
    }
}
