<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\ChannelWalletBalance;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Services\Shopee\ShopeeWalletService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FinancialDashboardController extends Controller
{
    private const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public function index(ShopeeWalletService $shopeeWallet): Response
    {
        $startOfMonth = Carbon::today()->startOfMonth();
        $start14 = Carbon::today()->subDays(13);

        $incomeAllTime = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_INCOME)->sum('amount');
        $expenseAllTime = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_EXPENSE)->sum('amount');

        $incomeMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_INCOME)->where('entry_date', '>=', $startOfMonth)->sum('amount');
        $expenseMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_EXPENSE)->where('entry_date', '>=', $startOfMonth)->sum('amount');

        // Pedido explícito 2026-08-09: lucro líquido de vendas de verdade
        // (receita − custo de produto − taxa de marketplace − anúncio) — é
        // o valor usado tanto no card "Lucro no Mês" do resumo quanto no
        // detalhamento "Lucro Líquido de Vendas" abaixo, mesma conta.
        // round() em cada soma: SUM de coluna decimal via PDO/SQLite pode
        // voltar com erro de ponto flutuante binário (ex.: 10.20 + 5.10 =
        // 15.299999999999999) — arredonda na fonte pra nunca vazar isso
        // pro dashboard nem pra conta de lucro líquido abaixo.
        $salesRevenueMonth = round((float) Order::query()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('total'), 2);

        $productCostMonth = round((float) Order::query()
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $startOfMonth)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(order_items.quantity * products.cost_price), 0) as total')
            ->value('total'), 2);

        $marketplaceFeeMonth = round((float) OrderChannelFee::query()
            ->where('computed_at', '>=', $startOfMonth)
            ->sum('fee_amount'), 2);

        $adSpendMonth = round((float) ChannelAdSpend::query()->where('date', '>=', $startOfMonth)->sum('spend'), 2);

        $productsWithCost = Product::query()->where('is_active', true)->whereNotNull('cost_price')->count();
        $productsActive = Product::query()->where('is_active', true)->count();

        $netProfitMonth = round($salesRevenueMonth - $productCostMonth - $marketplaceFeeMonth - $adSpendMonth, 2);

        return Inertia::render('Admin/Financeiro/Dashboard', [
            'summary' => [
                'balance' => $incomeAllTime - $expenseAllTime,
                'incomeMonth' => $incomeMonth,
                'expenseMonth' => $expenseMonth,
                // Pedido explícito 2026-08-09: "o lucro no mês sendo só o
                // líquido" — antes disso, este card mostrava
                // incomeMonth-expenseMonth (fluxo de caixa lançado à mão,
                // um conceito totalmente diferente de "a loja tá dando
                // lucro"). Agora é o mesmo netProfitMonth calculado abaixo
                // (receita real − custo − taxa − anúncio), pra ser
                // literalmente a métrica de "tá dando lucro ou não".
                'profitMonth' => $netProfitMonth,
                'salesRevenue' => (float) Order::query()->whereNot('status', Order::STATUS_CANCELLED)->sum('total'),
            ],
            // Saldo disponível pra saque nas plataformas — pedido explícito
            // 2026-08-09. Confirmado ao vivo: a Shopee tem isso de verdade
            // (current_balance no extrato da carteira). O Mercado Livre
            // devolveu "forbidden" consultando o saldo da conta Mercado
            // Pago — precisa de escopo de pagamentos que o app não tem
            // hoje, não um bug local; fica null (indisponível) até isso
            // ser resolvido do lado do cadastro do app na Mercado Livre.
            'walletBalances' => $this->walletBalances($shopeeWallet),
            'netProfit' => [
                'salesRevenueMonth' => $salesRevenueMonth,
                'productCostMonth' => $productCostMonth,
                'marketplaceFeeMonth' => $marketplaceFeeMonth,
                'adSpendMonth' => $adSpendMonth,
                'netProfitMonth' => $netProfitMonth,
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

    /**
     * @return array{shopee: float|null, mercado_livre: float|null, mercado_livre_as_of: string|null}
     */
    private function walletBalances(ShopeeWalletService $shopeeWallet): array
    {
        $shopeeConnected = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first()?->isConnected();
        $shopee = null;

        if ($shopeeConnected) {
            try {
                $shopee = $shopeeWallet->currentBalance();
            } catch (Throwable) {
                // Best-effort — o dashboard não pode ficar indisponível só
                // porque a consulta de saldo ao vivo falhou.
                $shopee = null;
            }
        }

        // Mercado Pago não tem "saldo agora" — só relatório assíncrono
        // (~15-20min pra ficar pronto, ver MercadoPagoWalletService).
        // ads:sync-wallet-balance já deixa isso pré-calculado aqui; não dá
        // pra consultar ao vivo numa requisição de página normal.
        $mlBalance = ChannelWalletBalance::query()->where('channel', 'mercado_livre')->first();

        return [
            'shopee' => $shopee,
            'mercado_livre' => $mlBalance?->balance !== null ? (float) $mlBalance->balance : null,
            'mercado_livre_as_of' => $mlBalance?->balance_as_of?->toDateTimeString(),
        ];
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
                'attributedGmv' => round((float) $row->attributed_gmv, 2),
                'spend' => round((float) $row->spend, 2),
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
