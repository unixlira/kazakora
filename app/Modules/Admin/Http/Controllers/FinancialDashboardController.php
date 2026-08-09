<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FinancialDashboardController extends Controller
{
    private const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public function index(): Response
    {
        $startOfMonth = Carbon::today()->startOfMonth();
        $start14 = Carbon::today()->subDays(13);

        $incomeAllTime = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_INCOME)->sum('amount');
        $expenseAllTime = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_EXPENSE)->sum('amount');

        $incomeMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_INCOME)->where('entry_date', '>=', $startOfMonth)->sum('amount');
        $expenseMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_EXPENSE)->where('entry_date', '>=', $startOfMonth)->sum('amount');

        // Pedido explícito 2026-08-09: lucro líquido de vendas de verdade
        // (receita − custo de produto − taxa de marketplace − anúncio),
        // separado do "profitMonth" acima (que é fluxo de caixa manual,
        // conceito diferente — entrada/saída lançada à mão).
        $salesRevenueMonth = (float) Order::query()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('total');

        $productCostMonth = (float) Order::query()
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $startOfMonth)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(order_items.quantity * products.cost_price), 0) as total')
            ->value('total');

        $marketplaceFeeMonth = (float) OrderChannelFee::query()
            ->where('computed_at', '>=', $startOfMonth)
            ->sum('fee_amount');

        $adSpendMonth = (float) ChannelAdSpend::query()->where('date', '>=', $startOfMonth)->sum('spend');

        $productsWithCost = Product::query()->where('is_active', true)->whereNotNull('cost_price')->count();
        $productsActive = Product::query()->where('is_active', true)->count();

        return Inertia::render('Admin/Financeiro/Dashboard', [
            'summary' => [
                'balance' => $incomeAllTime - $expenseAllTime,
                'incomeMonth' => $incomeMonth,
                'expenseMonth' => $expenseMonth,
                'profitMonth' => $incomeMonth - $expenseMonth,
                'salesRevenue' => (float) Order::query()->whereNot('status', Order::STATUS_CANCELLED)->sum('total'),
            ],
            'netProfit' => [
                'salesRevenueMonth' => $salesRevenueMonth,
                'productCostMonth' => $productCostMonth,
                'marketplaceFeeMonth' => $marketplaceFeeMonth,
                'adSpendMonth' => $adSpendMonth,
                'netProfitMonth' => $salesRevenueMonth - $productCostMonth - $marketplaceFeeMonth - $adSpendMonth,
                // Sinaliza dado incompleto em vez de deixar o número
                // parecer preciso quando não é — pedido explícito
                // 2026-08-09 (nenhum produto tem custo cadastrado hoje).
                'productsWithCost' => $productsWithCost,
                'productsActive' => $productsActive,
                // Shopee só passou a gravar taxa real agora (2026-08-09,
                // via escrow) — pedido feito antes disso ainda não tem
                // OrderChannelFee, mesmo já tendo sido vendido.
                'feeTrackedChannels' => ['mercado_livre', 'shopee'],
            ],
            'adSpendByChannel' => $this->adSpendByChannel($startOfMonth),
            'adSpendSeries' => $this->adSpendSeries($start14),
            'cashFlowSeries' => $this->cashFlowSeries($start14),
        ]);
    }

    private function adSpendByChannel(Carbon $startOfMonth): array
    {
        return ChannelAdSpend::query()
            ->selectRaw('channel, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(attributed_orders) as attributed_orders, SUM(attributed_gmv) as attributed_gmv, SUM(spend) as spend')
            ->where('date', '>=', $startOfMonth)
            ->groupBy('channel')
            ->get()
            ->map(fn ($row) => [
                'channel' => $row->channel,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'attributedOrders' => (int) $row->attributed_orders,
                'attributedGmv' => (float) $row->attributed_gmv,
                'spend' => (float) $row->spend,
            ])
            ->values()
            ->all();
    }

    private function adSpendSeries(Carbon $start): array
    {
        $rows = ChannelAdSpend::query()
            ->selectRaw('date, channel, SUM(spend) as spend')
            ->where('date', '>=', $start)
            ->groupBy('date', 'channel')
            ->get();

        $series = [];

        for ($date = $start->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $key = $date->toDateString();
            $dayRows = $rows->where('date', $key);

            $series[] = [
                'date' => $key,
                'shopee' => (float) ($dayRows->firstWhere('channel', 'shopee')?->spend ?? 0),
                'mercado_livre' => (float) ($dayRows->firstWhere('channel', 'mercado_livre')?->spend ?? 0),
            ];
        }

        return $series;
    }

    private function cashFlowSeries(Carbon $start): array
    {
        $income = CashFlowEntry::query()
            ->selectRaw('entry_date as date, SUM(amount) as total')
            ->where('type', CashFlowEntry::TYPE_INCOME)
            ->where('entry_date', '>=', $start)
            ->groupBy('entry_date')
            ->pluck('total', 'date');

        $expense = CashFlowEntry::query()
            ->selectRaw('entry_date as date, SUM(amount) as total')
            ->where('type', CashFlowEntry::TYPE_EXPENSE)
            ->where('entry_date', '>=', $start)
            ->groupBy('entry_date')
            ->pluck('total', 'date');

        $series = [];

        for ($date = $start->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'income' => (float) ($income[$key] ?? 0),
                'expense' => (float) ($expense[$key] ?? 0),
            ];
        }

        return $series;
    }
}
