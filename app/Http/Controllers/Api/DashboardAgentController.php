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

    /** Rótulo pt-BR do status pro payload de queue() — mesmo texto de Admin\DashboardController::STATUS_LABELS, duplicado aqui de propósito (DTO simples do KoraSync, não vale acoplar os dois a um enum/trait compartilhado por 6 valores fixos). */
    private const STATUS_LABELS = [
        Order::STATUS_PENDING => 'Pendente',
        Order::STATUS_AWAITING_PAYMENT => 'Aguardando pagamento',
        Order::STATUS_PAID => 'Pago',
        Order::STATUS_SHIPPED => 'Enviado',
        Order::STATUS_COMPLETED => 'Concluído',
        Order::STATUS_CANCELLED => 'Cancelado',
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
        //
        // BUG REAL 2026-08-17 ("as métricas não estão funcionando", achado
        // varrendo todo painel de métricas do sistema atrás do mesmo tipo
        // de bug já corrigido no dashboard admin): SUM(total) aqui inclui
        // frete (shipping_cost), que nunca é receita do vendedor — mesma
        // causa raiz já documentada em metrics() logo abaixo (que já usa
        // sum('subtotal') corretamente) e no dashboard admin, ver
        // DashboardController::index(). Os cards por canal do KoraSync
        // (RevenueMonth/RevenueToday de ChannelStatusDto) ficavam visíveis
        // ao lado dos cards de topo (RevenueMonth/RevenueToday de
        // DashboardMetricsDto, vindos de metrics()) na MESMA tela — somar
        // os cards por canal dava mais que o card de topo sempre que
        // houvesse pedido com frete, mesma inconsistência visível do
        // dashboard admin.
        $revenueMonthByChannel = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereIn('status', self::PAID_STATUSES)
            ->selectRaw('origin, SUM(subtotal) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $revenueTodayByChannel = Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->selectRaw('origin, SUM(subtotal) as total')
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

        // Achado real 2026-08-15: "Pedidos hoje" mostrava 17 quando o
        // usuário contava 18 pedidos recebidos no dia — a diferença era 1
        // pedido cancelado (Shopee UNPAID). Ao contrário de faturamento
        // (onde um pedido cancelado corretamente NÃO deve contar, ver
        // $revenueToday acima), "pedidos hoje" é sobre quantos pedidos
        // CHEGARAM no dia, não quantos foram pagos — mesmo critério "todo
        // pedido do dia, qualquer status" já decidido pra fila do
        // KoraSync (queue(), pedido explícito 2026-08-15). Por isso não usa
        // PAID_STATUSES aqui, de propósito.
        $salesToday = Order::query()
            ->where('created_at', '>=', $today)
            ->count();

        $salesYesterday = Order::query()
            ->whereBetween('created_at', [$yesterday, $today])
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

        // "Cancelamentos e devoluções do mês" do KoraSync v2.0 (pedido
        // explícito 2026-08-29) — soma cancelamento (canal cancelou o
        // pedido) com devolução/reclamação já contada em $returnsMonth
        // acima. Duas contagens de naturezas diferentes juntas de propósito
        // (é o card único "Cancelamentos e devoluções do mês" do layout
        // novo), não reaproveita $cancelledToday (que é só HOJE).
        $cancelledMonth = Order::query()
            ->where('status', Order::STATUS_CANCELLED)
            ->where('updated_at', '>=', $monthStart)
            ->count();

        // "Separados"/"Enviados" do card META DO DIA (pedido explícito
        // 2026-08-29) — embalado hoje (packed_at, ver packOrder()) e
        // etiqueta impressa hoje (mesma métrica já usada em
        // channels()::printedTodayByChannel, aqui somada entre todos os
        // canais pra um único número). Nenhum dos dois depende da janela
        // ontem/hoje de queue() — conta o dia inteiro, independente de
        // quando o pedido em si foi vendido.
        $packedToday = Order::query()
            ->where('packed_at', '>=', $today)
            ->count();

        $printedToday = PrintJob::query()
            ->where('status', PrintJob::STATUS_PRINTED)
            ->where('printed_at', '>=', $today)
            ->count();

        return response()->json([
            'revenue_today' => $revenueToday,
            'sales_today' => $salesToday,
            // Bruto (sem filtro de status), pra dar pra calcular
            // "vendas de hoje / ontem" no META DO DIA do KoraSync (mesma
            // base de $salesToday acima, sem PAID_STATUSES) — antes só a
            // variação percentual viajava, não os dois números crus.
            'sales_yesterday' => $salesYesterday,
            'cancelled_today' => $cancelledToday,
            'refunded_today' => $refundedToday,
            'cart_items_count' => $cartItemsCount,
            'net_profit_today' => $netProfitToday,
            'revenue_month' => $revenueMonth,
            'revenue_month_variation_pct' => $this->variationPct($revenueMonth, $revenueMonthPrev),
            'revenue_today_variation_pct' => $this->variationPct($revenueToday, $revenueYesterday),
            'sales_today_variation_pct' => $this->variationPct($salesToday, $salesYesterday),
            'returns_month' => $returnsMonth,
            'cancellations_and_returns_month' => $cancelledMonth + $returnsMonth,
            'packed_today' => $packedToday,
            'shipped_today' => $printedToday,
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
     * 2026-08-06: cards em destaque (3 desde 2026-08-15, eram 2) + lista com
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
     *
     * Pedido explícito 2026-08-15: "quero todos os pedidos aparecendo no
     * KoraSync hoje" — confirmado via AskUserQuestion que é literal,
     * qualquer status, não só "pago" (que era o filtro original, pensado
     * só pra fila de EXPEDIÇÃO — pedido esperando ser preparado). Filtro de
     * status removido, mantém só a data (hoje). 'status'/'status_label'
     * agora vão no payload pra quem exibe (KoraSync) poder diferenciar
     * visualmente um pedido acionável (pago, precisa embalar) de um que só
     * está passando por aqui pra registro (já enviado, cancelado,
     * aguardando pagamento) — packOrder() já rejeitava (409) tentar
     * embalar pedido não-pago antes disso, esse guard continua valendo.
     *
     * BUG REAL 2026-08-17, corrigido no mesmo dia: o corte "só hoje" acima
     * tem um efeito colateral não percebido em 2026-08-15 — um pedido pago
     * ontem e ainda não embalado (packed_at nulo) simplesmente cai fora da
     * janela [hoje, amanhã) na virada do dia e desaparece da fila, mesmo
     * continuando "em preparação" de verdade (usuário relatou pedidos de
     * ontem que sobraram pra embalar hoje e sumiram). Primeira correção
     * tentou "pago sem packed_at, sem limite de data" — mas isso trouxe de
     * volta um represamento de 32 pedidos pagos há semanas nunca embalados
     * (não é o cenário que o usuário quer ver todo dia), então foi revertida
     * a favor de um corte simples e explícito: **ontem + hoje**, qualquer
     * status, sem exceção pra pedido mais antigo. O painel web
     * (PrintJobController::index()) continua sem corte de data nenhum — é o
     * lugar certo pra ver um represamento antigo de verdade, se um dia
     * existir.
     *
     * Pedido explícito 2026-08-17: venda com entrega programada (Mercado
     * Livre "Coleta/Places" agendado, ver MercadoLivreDriver::
     * extractScheduledFor(), ChannelShipment.scheduled_for) precisa entrar
     * na fila do DIA AGENDADO, não do dia da venda — a venda pode ter
     * saído dias antes, mas o canal só libera a etiqueta perto da data
     * agendada, e é nesse dia que o operador precisa ver o pedido pra se
     * organizar (mesmo raciocínio de "controle" que já motivava
     * scheduledShipments(), só que dentro da fila principal também).
     * 'scheduled_for'/'label_ready' no payload (abaixo) alimentam o 3º
     * estado do botão do KoraSync ("Sem Etiqueta") — ver
     * OrderQueueCardViewModel.IsAwaitingLabel no app nativo.
     *
     * BUG REAL 2026-08-29 (achado no relato do usuário: venda do Mercado
     * Livre aparecendo na fila de preparação antes da data agendada) — a
     * condição original era um `orWhereHas` solto: `created_at` hoje/ontem
     * OU `scheduled_for` hoje/ontem, sem exclusão mútua. Um pedido vendido
     * HOJE mas com entrega agendada pra semana que vem batia na PRIMEIRA
     * condição (created_at) e entrava na fila mesmo faltando dias pra
     * etiqueta liberar — o "OU" nunca teve o efeito pretendido de "entra
     * pela data agendada QUANDO existe uma", só ampliava o critério.
     * Corrigido pra mútuo excludente: pedido COM entrega agendada só entra
     * pela janela de scheduled_for (nunca pela de created_at, não importa
     * quando foi vendido); pedido SEM entrega agendada continua entrando
     * pela janela de created_at, como sempre foi.
     *
     * Pedido explícito 2026-08-29 (KoraSync v2.0): a fila de hoje agora se
     * divide em duas abas — "Fila normal" (com estoque, pode separar já) e
     * "Sem estoque" (falta produto, precisa repor no fornecedor antes de
     * poder embalar) — ver partitionByStock() logo abaixo pra como a
     * divisão é calculada. 'pending_separation_count' é o total ainda por
     * separar (soma das duas abas, exceto pedido já embalado/cancelado) —
     * alimenta o card "Pendentes de separação" do META DO DIA.
     */
    public function queue(): JsonResponse
    {
        $today = now()->startOfDay();
        $yesterday = $today->clone()->subDay();
        $tomorrow = $today->clone()->addDay();
        $monthStart = $today->clone()->startOfMonth();

        $relations = [
            'items:id,order_id,product_id,product_name,quantity',
            // stock a mais que a versão anterior buscava — é o dado que
            // partitionByStock() usa pra decidir a aba. SKU continua vindo
            // junto, pro payload de shortage.
            'items.product:id,sku,stock',
            'channelShipment:id,order_id,status,scheduled_for',
        ];

        // Pedido que NÃO é "pago e ainda não embalado" — já embalado,
        // aguardando pagamento, ou já enviado/concluído — exibido como está
        // na Fila normal quando cai na janela ontem/hoje, SEM entrar na
        // conta de estoque. Pedido explícito 2026-08-15: mostrar TODO
        // pedido do dia, qualquer status (auditoria do dia, não fila de
        // separação em si).
        //
        // BUG REAL 2026-08-29, relatado pelo usuário ("está aparecendo
        // vendas antigas já entregues"): a versão anterior usava só "status
        // != cancelled" pra decidir quem entra na conta de estoque — isso
        // incluía pedido já ENVIADO/CONCLUÍDO sem packed_at (resolvido antes
        // desse campo existir, ou por fora do KoraSync), fazendo o sistema
        // achar que uma venda já entregue há semanas ainda "precisava de
        // estoque pra separar". Só status PAID representa de verdade "ainda
        // precisa ser separado" — os outros status (mesmo sem packed_at) já
        // saíram da mão ou nem foram pagos ainda, não fazem parte da conta
        // de estoque de jeito nenhum.
        //
        // BUG REAL 2026-08-29 (2ª correção no mesmo dia, pedido #913 — venda
        // lançada errada, cancelada de propósito pra sumir da fila):
        // CANCELLED saiu de vez da regra de "auditoria do dia" acima — a
        // exclusão explícita abaixo reverte especificamente esse status;
        // os outros (aguardando pagamento, enviado, concluído) continuavam
        // exibidos como antes.
        //
        // BUG REAL 2026-08-29 (3ª correção — pedido explícito do usuário,
        // "ajuste no KoraSync/KazaKora": pedido embalado continuava
        // aparecendo na fila mesmo depois do ponto de coleta escanear o
        // pacote): SHIPPED/COMPLETED saem da mesma regra de "auditoria do
        // dia" agora, junto com CANCELLED. O status já É atualizado
        // corretamente pro canal confirmar a coleta de verdade — ver
        // ShipmentService::processWebhook() (Mercado Livre) e
        // ShopeeDriver::mapOrderStatus() (Shopee), os dois já mapeiam
        // "shipped"/"delivered"/"completed" pro Order real. O que faltava
        // NÃO era detectar o status (isso já funciona), era a fila parar
        // de mostrar pedido nesse status "pra auditoria" — decisão
        // 2026-08-15 revertida especificamente pra esses 2 status agora.
        $displayOnlyOrders = Order::query()
            ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->where(function ($query) {
                $query->whereNotNull('packed_at')->orWhere('status', '!=', Order::STATUS_PAID);
            })
            ->where(function ($query) use ($yesterday, $tomorrow) {
                // Sem entrega agendada — entra pela data normal da venda,
                // como sempre foi.
                $query->where(function ($query) use ($yesterday, $tomorrow) {
                    $query->whereBetween('created_at', [$yesterday, $tomorrow])
                        ->whereDoesntHave('channelShipment', function ($query) {
                            $query->whereNotNull('scheduled_for');
                        });
                })
                    // Com entrega agendada — só entra no dia agendado,
                    // NUNCA no dia da venda (BUG REAL 2026-08-29: pedido
                    // vendido hoje mas agendado pra semana que vem batia
                    // na condição de created_at e entrava antes da hora).
                    ->orWhereHas('channelShipment', function ($query) use ($yesterday, $tomorrow) {
                        $query->whereBetween('scheduled_for', [$yesterday, $tomorrow]);
                    });
            })
            ->with($relations)
            ->withSum('items as units_count', 'quantity')
            ->get();

        // min(início do mês, ontem) — não simplesmente $monthStart: se HOJE
        // for dia 1º do mês, "ontem" cai no mês anterior, e o carry-over de
        // pedido pago de ontem ainda não embalado (garantido desde
        // 2026-08-17, ver isInTodayWindow) não pode quebrar só por causa do
        // corte mensal novo — ele precisa continuar entrando em
        // $actionableOrders pra aparecer na Fila normal amanhã de manhã.
        $actionableSince = $yesterday->lessThan($monthStart) ? $yesterday : $monthStart;

        // SÓ pedido PAGO e ainda não embalado entra na conta de estoque — é
        // o único status que representa de verdade "precisa separar, tem
        // que ter produto pra isso" (ver BUG REAL acima). created_at >=
        // início do mês (pedido explícito 2026-08-29: "sem estoque deve ser
        // mostrado a partir desse mês em diante") — não é mais "sem limite
        // nenhum" (1ª correção no mesmo dia, também baseada num relato real,
        // mas longe demais: a aba é sobre reposição em aberto, não um
        // arquivo morto de anos). EXCETO pedido com entrega agendada
        // (scheduled_for) — esse sempre entra na conta de estoque não
        // importa quando foi vendido (pode ter sido há semanas, ver
        // MercadoLivreDriver::extractScheduledFor()): o que importa pra ele
        // é a data de entrega, não a data da venda, e o estoque precisa
        // ficar reservado pra ele desde já (FIFO de verdade), não só a
        // partir do dia em que a etiqueta libera. O corte pra Fila normal é
        // aplicado depois, em PHP (isInTodayWindow), sobre quem tem estoque
        // OK — sem precisar de uma 2ª query.
        $actionableOrders = Order::query()
            ->where('status', Order::STATUS_PAID)
            ->whereNull('packed_at')
            ->where(function ($query) use ($actionableSince) {
                $query->where('created_at', '>=', $actionableSince)
                    ->orWhereHas('channelShipment', function ($query) {
                        $query->whereNotNull('scheduled_for');
                    });
            })
            ->with($relations)
            ->withSum('items as units_count', 'quantity')
            ->orderBy('id')
            ->get();

        [$withStock, $outOfStock, $shortages] = $this->partitionByStock($actionableOrders);

        // FIFO calculado sobre TODO o backlog acionável (não só hoje) —
        // pedido antigo represado sem estoque continua tendo prioridade
        // real sobre uma reposição futura, mesmo não aparecendo na Fila
        // normal (que é só a "vitrine" de hoje).
        $withStockToday = $withStock->filter(fn (Order $order) => $this->isInTodayWindow($order, $yesterday, $tomorrow));

        // BUG REAL 2026-08-31 (relatado pelo usuário: "vendas do mercado
        // livre estao canceladas algumas horas depois e não apareceram na
        // fila de cancelados... isso foi um erro que eu poderia ter
        // enviado os produtos") — $displayOnlyOrders acima EXCLUI
        // CANCELLED explicitamente (whereNotIn no topo), e $actionableOrders
        // só pega status=PAID — nenhuma das duas nunca incluiu pedido
        // cancelado, então um pedido que estava visível (pago, na fila) e
        // foi cancelado horas depois simplesmente SUMIA da tela inteira no
        // próximo poll, em vez de mudar pra aba "Cancelados" (o
        // client-side do KoraSync já sabe separar por status=="cancelled"
        // — MainViewModel.UpdateOrderQueue — só faltava o servidor não
        // esconder esses pedidos antes disso chegar a acontecer). Pedido
        // cancelado nas últimas ~48h (mesma janela ontem/hoje já usada em
        // toda essa função) agora entra também, especificamente pra isso —
        // sem essa visibilidade, ninguém percebe que precisa TIRAR um
        // pedido já separado/em mãos da remessa.
        $recentlyCancelledOrders = Order::query()
            ->where('status', Order::STATUS_CANCELLED)
            ->whereBetween('updated_at', [$yesterday, $tomorrow])
            ->with($relations)
            ->withSum('items as units_count', 'quantity')
            ->get();

        $normalOrders = $displayOnlyOrders->merge($withStockToday)->merge($recentlyCancelledOrders)->sortByDesc('id')->values();
        $outOfStockOrders = $outOfStock->sortByDesc('id')->values();

        $mapper = fn (Order $order) => $this->mapQueueOrder($order, $shortages[$order->id] ?? []);

        return response()->json([
            'queue' => $normalOrders->map($mapper)->values(),
            'out_of_stock' => $outOfStockOrders->map($mapper)->values(),
            // Todo o backlog sem estoque conta aqui também (não só o de
            // hoje) — é trabalho pendente de verdade, só esperando repor.
            'pending_separation_count' => $withStockToday->count() + $outOfStockOrders->count(),
        ]);
    }

    /**
     * "Ontem+hoje" pra exibição normal, OU o dia agendado quando o pedido
     * tem entrega/coleta programada (ver comentário completo em queue(),
     * seção $displayOnlyOrders, mesma regra replicada aqui em PHP pra
     * reaplicar sobre $withStock sem precisar de uma 2ª query no banco).
     *
     * BUG REAL 2026-08-31 (RESSURGIU no mesmo dia — já tinha sido corrigido
     * antes, mas um deploy de rotina sem relação nenhuma resetou este
     * arquivo pro estado do git via rsync --delete e o fix nunca tinha sido
     * commitado; ao reconstruir as outras correções depois do reset,
     * esqueci de reaplicar esta também. Achado de novo no relato do
     * usuário: "tem pedido na fila errado" — pedidos #863/893/894/910/925/
     * 940/967/969, agendados pro dia 31/08 mas com etiqueta AINDA não
     * liberada pelo Mercado Livre, apareciam na Fila normal só por a data
     * agendada ter chegado, mesmo sem poder ser separado/impresso de
     * verdade ainda): pedido com scheduled_for só entra na janela de hoje
     * quando a etiqueta JÁ foi liberada pelo canal — chegar a data
     * prometida não basta, o Mercado Livre pode atrasar a liberação de
     * verdade (foi exatamente o caso agora, madrugada do dia agendado, ML
     * ainda não liberou nenhuma). Sem entrega agendada (pedido normal),
     * comportamento intacto — só created_at importa, como sempre foi.
     *
     * Sem janela de data quando tem entrega agendada — só "etiqueta
     * liberada, sim ou não" importa nesse caso (não "liberada dentro de
     * ontem/hoje/amanhã"): a data agendada em si já decidiu quando o
     * pedido entrou em $actionableOrders (ver query lá, scheduled_for not
     * null passa sem olhar created_at); se o Mercado Livre atrasar a
     * liberação de verdade pra depois da data prometida, o pedido não pode
     * deixar de aparecer só porque "passou da janela" — continua sendo
     * trabalho pendente até ser embalado.
     */
    private function isInTodayWindow(Order $order, \Carbon\Carbon $yesterday, \Carbon\Carbon $tomorrow): bool
    {
        $shipment = $order->channelShipment;
        $scheduledFor = $shipment?->scheduled_for;

        if ($scheduledFor !== null) {
            return in_array($shipment?->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true);
        }

        return $order->created_at->between($yesterday, $tomorrow);
    }

    /**
     * Divide os pedidos AINDA NÃO resolvidos (já filtrados por quem chama —
     * ver queue()) entre "tem estoque pra separar agora" e "sem estoque,
     * precisa repor no fornecedor antes" — pedido explícito 2026-08-29.
     *
     * BUG REAL 2026-08-31 (relatado pelo usuário, pedido #1108 — "carregador
     * de celular tem sim no estoque", produto com stock=28 de verdade):
     * a versão anterior fazia uma simulação FIFO reconstruindo um "estoque
     * disponível" a partir de Product::stock e ia decrementando 1x por
     * pedido pendente na fila — mas Product::stock JÁ é debitado de
     * verdade, atomicamente, no momento de CADA venda (StockManager::
     * adjust(), chamado por OrderImportService/ManualOrderService logo na
     * importação/criação do pedido — confirmado ao vivo pelos
     * StockMovements reais). Ou seja: Product::stock já reflete "quanto
     * sobra depois de TODAS as vendas pendentes", não "quanto tinha antes
     * delas" — rodar a simulação de novo em cima disso descontava a MESMA
     * unidade duas vezes. Resultado real: produto com 28 de estoque e 34
     * pedidos pagos/não embalados (a maioria do mesmo SKU popular) fazia a
     * simulação "zerar" já no 28º pedido em ordem de id, marcando os 6
     * seguintes como sem estoque — mesmo cada um deles já tendo debitado
     * sua própria unidade normalmente, sem problema real nenhum.
     *
     * StockManager::adjust() já clampa em 0 (nunca fica negativo) e já
     * dispara OversellDetectedNotification pros admins na hora real em que
     * uma venda de fato não tem unidade física suficiente — é ali que
     * "vendeu mais do que tinha" já é detectado e avisado, não precisa (e
     * não deve) ser reconstruído aqui de novo. Correção: sem simulação
     * nenhuma — cada pedido pendente só entra em "sem estoque" se o
     * produto está com stock <= 0 NESTE INSTANTE (repõe no fornecedor,
     * volta sozinho pra Fila normal no próximo poll, mesma dinâmica de
     * sempre). Continua em tempo real, sem estado novo persistido.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $actionableOrders  já carregado com items.product:stock (ver queue()) — só pedido PAID e ainda não embalado, ver comentário completo lá.
     * @return array{0: \Illuminate\Support\Collection<int, Order>, 1: \Illuminate\Support\Collection<int, Order>, 2: array<int, array<int, array{sku: ?string, name: string, missing: int}>>}
     */
    private function partitionByStock(\Illuminate\Support\Collection $actionableOrders): array
    {
        $withStock = collect();
        $withoutStock = collect();
        $shortages = [];

        foreach ($actionableOrders->sortBy('id') as $order) {
            $orderShortage = [];

            foreach ($order->items as $item) {
                $productId = $item->product_id;

                // Item avulso sem produto local cadastrado (sem cost_price,
                // sem estoque pra controlar) nunca vira "sem estoque" por
                // falta de cadastro — não é esse o problema que essa aba
                // resolve.
                if ($productId === null) {
                    continue;
                }

                $stock = (int) ($item->product?->stock ?? 0);

                if ($stock <= 0) {
                    $orderShortage[] = [
                        'sku' => $item->product?->sku,
                        'name' => $item->product_name,
                        'missing' => $item->quantity,
                    ];
                }
            }

            if ($orderShortage === []) {
                $withStock->push($order);
            } else {
                $withoutStock->push($order);
                $shortages[$order->id] = $orderShortage;
            }
        }

        return [$withStock, $withoutStock, $shortages];
    }

    /**
     * @param  array<int, array{sku: ?string, name: string, missing: int}>  $stockShortage  vazio pra pedido com estoque OK — ver partitionByStock().
     * @return array<string, mixed>
     */
    private function mapQueueOrder(Order $order, array $stockShortage): array
    {
        return [
            'id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'channel' => $order->origin,
            // Achado real 2026-08-15 (pedido #371): a própria Shopee manda
            // o nome do comprador mascarado com asterisco
            // ("S******o") pra pedido cancelado/não pago — confirmado ao
            // vivo contra a API deles, não é bug nosso nem dado perdido no
            // nosso lado, o nome de verdade simplesmente não existe mais
            // na resposta. Troca só na EXIBIÇÃO desta fila (não mexe em
            // shipping_name, que fica intacto pra qualquer outro uso —
            // NF-e, histórico etc.) por um texto que não confunde o
            // operador achando que é o nome real truncado.
            'customer_name' => str_contains($order->shipping_name, '*')
                ? 'Cliente (dados ocultados pelo canal)'
                : $order->shipping_name,
            'units_count' => (int) $order->units_count,
            'packed_at' => $order->packed_at,
            'status' => $order->status,
            'status_label' => self::STATUS_LABELS[$order->status] ?? $order->status,
            // product_id (pedido explícito 2026-08-30 — "múltiplos
            // produtos de 1 pedido... o pessoal identifica pela imagem/
            // foto para embalar") — o cliente busca 1 foto por produto
            // distinto em GET dashboard/queue/{order}/image/{productId}
            // (ver queueOrderProductImage() abaixo), não mais 1 foto só
            // pro pedido inteiro.
            'products' => $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'sku' => $item->product?->sku,
            ]),
            'created_at' => $order->created_at,
            // Null pra pedido normal (sem entrega programada). Presente ⇒
            // KoraSync trata como "venda agendada" — 3º estado do botão
            // (ver OrderQueueCardViewModel.IsAwaitingLabel).
            'scheduled_for' => $order->channelShipment?->scheduled_for,
            // Só relevante quando scheduled_for não é null: true quando o
            // canal já liberou a etiqueta de verdade (mesmos 2 status
            // "prontos" usados em scheduledShipments()), false enquanto o
            // pedido está preparado mas ainda esperando o canal liberar.
            'label_ready' => in_array(
                $order->channelShipment?->status,
                [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED],
                true,
            ),
            // Vazio pra pedido com estoque OK — presente ⇒ KoraSync mostra
            // "falta Nx SKU" no card da aba Sem Estoque (pedido explícito
            // 2026-08-29, ver partitionByStock()).
            'stock_shortage' => $stockShortage,
        ];
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
     * Uma foto por PRODUTO DISTINTO do pedido (pedido explícito
     * 2026-08-30 — "múltiplos produtos de 1 pedido... o pessoal
     * identifica pela imagem/foto para embalar"). $product é o product_id
     * vindo em queue()/products.product_id. Mesmo padrão de 404 "sem
     * imagem" de queueOrderImage() acima.
     */
    public function queueOrderProductImage(Order $order, int $product, OrderImageArchiveService $images): mixed
    {
        $bytes = $images->bytes($order, $product);

        if ($bytes === null) {
            throw new NotFoundHttpException('Produto sem imagem disponível.');
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
     *
     * ?channel= (pedido explícito 2026-08-29): filtro opcional — a aba
     * "Mercado Livre" do KoraSync v2.0 chama com channel=mercado_livre pra
     * ver só as vendas futuras desse canal (é praticamente o único que usa
     * entrega agendada hoje, mas o filtro é genérico, não hardcoded). Sem
     * o parâmetro, comportamento idêntico a antes (todos os canais).
     */
    public function scheduledShipments(Request $request): JsonResponse
    {
        $shipments = ChannelShipment::query()
            ->whereNotNull('scheduled_for')
            ->whereNotIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            // Mesma regra do 2º/3º bug real 2026-08-29 em queue(): pedido
            // cancelado, enviado ou concluído não precisa mais de nenhuma
            // ação (nem aqui, na aba de agendados do ML) — sem isso, um
            // pedido já despachado com scheduled_for continuava aparecendo
            // pra sempre nesta lista.
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED]))
            ->when(
                $request->query('channel'),
                fn ($query, $channel) => $query->where('channel', $channel),
            )
            ->with(['order:id,external_order_id,origin,shipping_name,created_at', 'order.items:id,order_id,product_name,quantity'])
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
            // Data real da venda (pedido explícito 2026-08-29: "Data do
            // Pedido... Criado:", pro card da aba Mercado Livre no
            // KoraSync) — mesmo campo já exposto em queue() (ver
            // mapQueueOrder), só que essa aba nunca tinha exposto antes.
            'created_at' => $shipment->order->created_at,
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
