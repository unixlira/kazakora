<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\PrintJob;
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

        $lastPrintedJobs = PrintJob::query()
            ->join('orders', 'orders.id', '=', 'print_jobs.order_id')
            ->where('print_jobs.status', PrintJob::STATUS_PRINTED)
            ->selectRaw('orders.origin, MAX(print_jobs.printed_at) as last_printed_at')
            ->groupBy('orders.origin')
            ->pluck('last_printed_at', 'orders.origin');

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

        // Mesma definição de "devolução" usada em metrics() — pedido com ao
        // menos um Payment estornado (não existe integração real com
        // devolução física/reclamação de nenhum marketplace ainda).
        $returnsMonthByChannel = Order::query()
            ->whereHas('payments', function ($query) use ($monthStart) {
                $query->where('status', Payment::STATUS_REFUNDED)
                    ->where('updated_at', '>=', $monthStart);
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

        $revenueToday = (float) Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('total');

        $revenueYesterday = (float) Order::query()
            ->whereBetween('created_at', [$yesterday, $today])
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('total');

        $revenueMonth = (float) Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('total');

        $revenueMonthPrev = (float) Order::query()
            ->whereBetween('created_at', [$prevMonthStart, $prevMonthToDate])
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('total');

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

        // "Reembolsadas"/"devoluções" vem do status do Payment, não existe
        // status de devolução física no Order em si (nenhum marketplace tem
        // integração de reclamação/devolução real aqui ainda) — contamos
        // pedidos distintos com pelo menos um pagamento estornado.
        $refundedToday = Order::query()
            ->whereHas('payments', function ($query) use ($today) {
                $query->where('status', Payment::STATUS_REFUNDED)
                    ->where('updated_at', '>=', $today);
            })
            ->count();

        $returnsMonth = Order::query()
            ->whereHas('payments', function ($query) use ($monthStart) {
                $query->where('status', Payment::STATUS_REFUNDED)
                    ->where('updated_at', '>=', $monthStart);
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
            ->with(['order:id,external_order_id', 'order.items:id,order_id,product_id,product_name,quantity', 'order.items.product:id,sku'])
            ->latest('id')
            ->limit(50)
            ->get(['id', 'order_id', 'channel', 'status', 'error_message', 'created_at', 'printed_at']);

        $result = $jobs->map(fn (PrintJob $job) => [
            'id' => $job->id,
            'channel' => $job->channel,
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
}
