<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Support\GruposDeCanais;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::today()->subDays(29);
        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : Carbon::today()->endOfDay();

        // Marketplaces x E-commerces (Lira, 17/11/2026): o relatório é de um
        // grupo só, nunca dos dois somados.
        $grupo = (string) $request->query('grupo', 'marketplaces');
        $grupo = array_key_exists($grupo, GruposDeCanais::SLUGS) ? $grupo : 'marketplaces';
        $canais = GruposDeCanais::canais($grupo);

        // Auditoria do financeiro (Lira, 27/11/2026): esta tela somava todo pedido não cancelado
        // (aguardando pagamento, nota técnica de devolução, estornado, nota avulsa repetida) e
        // mostrava bem mais receita que as outras telas. Agora é a mesma venda das outras telas: Order::vendaEfetiva(), pedido pago/enviado/entregue e a
        // receita de ContributionMargin::receitaSql() (TikTok pelas vendas líquidas, site sem os
        // descontos da loja, Amazon com o frete do comprador).
        $orders = Order::query()
            ->vendaEfetiva()
            ->whereIn('status', ContributionMargin::REVENUE_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->doCanal($canais);
        $receita = ContributionMargin::receitaSql();

        // BUG REAL 2026-08-17 ("as métricas não estão funcionando", achado
        // varrendo todo painel de métricas atrás do mesmo tipo de bug já
        // corrigido no dashboard admin): SUM(total) inclui frete
        // (shipping_cost), que nunca é receita do vendedor — 'summary.
        // revenue' logo abaixo, mesma tela, já usava subtotal desde
        // sempre. Somar a coluna "Faturamento" dessa tabela dia a dia
        // dava mais que o card "FATURAMENTO NO PERÍODO" sempre que houvia
        // pedido com frete, mesma inconsistência visível do dashboard
        // admin (ver DashboardController::revenueByChannel()).
        $salesByDay = (clone $orders)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders_count, SUM('.$receita.') as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.order_id', (clone $orders)->select('orders.id'))
            ->selectRaw('order_items.product_name, SUM(order_items.quantity) as quantity_sold, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity_sold')
            ->limit(10)
            ->get();

        return Inertia::render('Admin/Relatorios/Index', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'grupo' => $grupo],
            'salesByDay' => $salesByDay,
            'topProducts' => $topProducts,
            'summary' => [
                'ordersCount' => (clone $orders)->count(),
                // subtotal, não total — 'total' inclui frete (não é receita
                // do vendedor), ver comentário em
                // FinancialDashboardController::index().
                'revenue' => round((float) (clone $orders)->sum(\Illuminate\Support\Facades\DB::raw($receita)), 2),
            ],
        ]);
    }
}
