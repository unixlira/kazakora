<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Http\JsonResponse;

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
    ];

    public function channels(): JsonResponse
    {
        $today = now()->startOfDay();

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

        $channels = collect(self::CHANNELS)->map(function (string $channel) use ($accounts, $lastOrders, $lastPrintedJobs, $printedTodayByChannel) {
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
            ];
        });

        return response()->json(['channels' => $channels]);
    }

    public function metrics(): JsonResponse
    {
        $today = now()->startOfDay();

        $revenueToday = (float) Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('total');

        $salesToday = Order::query()
            ->where('created_at', '>=', $today)
            ->whereIn('status', self::PAID_STATUSES)
            ->count();

        $cancelledToday = Order::query()
            ->where('status', Order::STATUS_CANCELLED)
            ->where('updated_at', '>=', $today)
            ->count();

        // "Reembolsadas" vem do status do Payment, não existe status de
        // reembolso no Order em si — contamos pedidos distintos com pelo
        // menos um pagamento marcado como refunded hoje.
        $refundedToday = Order::query()
            ->whereHas('payments', function ($query) use ($today) {
                $query->where('status', Payment::STATUS_REFUNDED)
                    ->where('updated_at', '>=', $today);
            })
            ->count();

        // Carrinhos ativos: CartSnapshot já é filtrado pra excluir sessões
        // expiradas (mesma janela usada no dashboard admin), e uma linha só
        // existe enquanto o carrinho tem itens — não precisa filtro extra.
        $cartItemsCount = (int) CartSnapshot::query()
            ->where('updated_at', '>=', now()->subMinutes((int) config('session.lifetime')))
            ->sum('items_count');

        return response()->json([
            'revenue_today' => $revenueToday,
            'sales_today' => $salesToday,
            'cancelled_today' => $cancelledToday,
            'refunded_today' => $refundedToday,
            'cart_items_count' => $cartItemsCount,
        ]);
    }
}
