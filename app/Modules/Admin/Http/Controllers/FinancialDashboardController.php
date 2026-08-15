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
use App\Modules\Marketplace\Support\FlexDeliveryService;
use App\Services\Shopee\ShopeeWalletService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FinancialDashboardController extends Controller
{
    private const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public function index(ShopeeWalletService $shopeeWallet, FlexDeliveryService $flexDelivery): Response
    {
        $startOfMonth = Carbon::today()->startOfMonth();
        $start14 = Carbon::today()->subDays(13);

        // Custo do Mercado Envios Flex (R$/entrega, ver FlexDeliveryService)
        // abate o lucro líquido — pedido explícito 2026-08-10, mesmo dia em
        // que a tela de controle do Flex foi criada. "Desde o início" varre
        // uma janela ampla o bastante pra cobrir qualquer envio real já
        // registrado (a loja não existe antes de 2026) em vez de tentar
        // achar a data exata do primeiro pedido.
        $flexCostMonth = $flexDelivery->summaryForPeriod($startOfMonth, Carbon::today())['total'];
        $flexCostAllTime = $flexDelivery->summaryForPeriod(Carbon::create(2026, 1, 1), Carbon::today())['total'];

        $incomeMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_INCOME)->where('entry_date', '>=', $startOfMonth)->sum('amount');
        $expenseMonth = (float) CashFlowEntry::query()->where('type', CashFlowEntry::TYPE_EXPENSE)->where('entry_date', '>=', $startOfMonth)->sum('amount');

        // Pedido explícito 2026-08-09/10/14: lucro líquido de vendas de
        // verdade (receita − custo de produto − taxa de marketplace −
        // anúncio − Flex) — é o valor usado tanto no card "Lucro no Mês"
        // do resumo quanto no detalhamento "Lucro Líquido de Vendas"
        // abaixo, mesma conta. Taxa de marketplace ficou de fora dessa
        // conta entre 2026-08-10 e 2026-08-14 (pedido explícito da época:
        // "o lucro liquido é realmente só o que sobra do desconto do
        // material, do ads") — reconsiderado em 2026-08-14 (achado real
        // auditando o dashboard: 57 pedidos Shopee sem taxa nenhuma
        // registrada inflavam o número mostrado) e voltou a entrar, é um
        // custo real (~12-20% da receita).
        // round() em cada soma: SUM de coluna decimal via PDO/SQLite pode
        // voltar com erro de ponto flutuante binário (ex.: 10.20 + 5.10 =
        // 15.299999999999999) — arredonda na fonte pra nunca vazar isso
        // pro dashboard nem pra conta de lucro líquido abaixo.
        // BUG REAL 2026-08-15 (achado investigando reclamação real do
        // usuário — pedido #305 Shopee: R$44,99 no Seller Center, R$58,24
        // aqui): 'total' = subtotal + frete (shipping_cost) — correto pro
        // VALOR DA NOTA FISCAL (SEFAZ exige, ver ShopeeDriver::
        // importOrder()), mas o frete pago pelo comprador/Shopee ao
        // transportador nunca é receita do vendedor, e netProfitMonth logo
        // abaixo não tinha NENHUM custo de frete equivalente subtraído —
        // então o frete simplesmente inflava receita E lucro em todo pedido
        // com frete, desde que essas métricas existem (2026-08-09).
        // CashFlowController já fazia certo (gross_amount = subtotal);
        // troquei 'total' por 'subtotal' aqui pra bater com essa definição.
        $salesRevenueMonth = round((float) Order::query()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('subtotal'), 2);

        $productCostMonth = round((float) Order::query()
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $startOfMonth)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(order_items.quantity * products.cost_price), 0) as total')
            ->value('total'), 2);

        // BUG REAL 2026-08-14: filtrava por computed_at (quando a taxa foi
        // CALCULADA/gravada no nosso banco) em vez da data do PEDIDO — os
        // dois só coincidem por acaso quando a taxa é gravada na hora
        // (fluxo normal). Um backfill retroativo (ver
        // BackfillShopeeOrderFeesCommand, criado no mesmo dia pra fechar a
        // lacuna de 57 pedidos Shopee de antes de 2026-08-09 sem taxa
        // nenhuma) grava computed_at=agora pra pedidos de dias/semanas
        // atrás — com o filtro antigo, toda essa taxa recém-preenchida
        // "pularia" pro mês em que o backfill rodou, nunca pro mês real da
        // venda. Junta com orders e filtra pela data do pedido, igual
        // productCostMonth/salesRevenueMonth logo acima.
        $marketplaceFeeMonth = round((float) OrderChannelFee::query()
            ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id')
            ->where('orders.created_at', '>=', $startOfMonth)
            ->sum('order_channel_fees.fee_amount'), 2);

        $adSpendMonth = round((float) ChannelAdSpend::query()->where('date', '>=', $startOfMonth)->sum('spend'), 2);

        // Pedido explícito 2026-08-15: frete continua fora da conta de
        // faturamento/lucro (é pago pelo comprador/canal à transportadora,
        // nunca chega no vendedor — ver comentário em $salesRevenueMonth
        // acima), mas o usuário quer o valor visível/rastreável mesmo assim
        // — puramente informativo, nunca subtraído nem somado em nenhuma
        // conta de lucro. O dado em si já vive em orders.shipping_cost
        // desde sempre (ShopeeDriver::importOrder()); isso só soma pra
        // exibição.
        $shippingCostMonth = round((float) Order::query()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('shipping_cost'), 2);

        $productsWithCost = Product::query()->where('is_active', true)->whereNotNull('cost_price')->count();
        $productsActive = Product::query()->where('is_active', true)->count();

        // Pedido explícito 2026-08-14: taxa do marketplace (comissão real
        // Shopee/ML) agora entra na conta do lucro líquido — antes ficava
        // só "informativa" (pedido de 2026-08-10 pra excluir), mas é um
        // custo real (~12-20% da receita) e o usuário confirmou que
        // precisa estar aqui pro número refletir o dinheiro de verdade.
        $netProfitMonth = round($salesRevenueMonth - $productCostMonth - $marketplaceFeeMonth - $adSpendMonth - $flexCostMonth, 2);

        // Pedido explícito 2026-08-10: "faturamento liquido é o valor bruto
        // menos ads" — métrica distinta de lucro líquido (que também abate
        // o custo do material). Vai no lugar do card "Entradas no Mês", que
        // antes mostrava só o CashFlowEntry lançado à mão.
        $netRevenueMonth = round($salesRevenueMonth - $adSpendMonth, 2);

        // Pedido explícito 2026-08-09: cards do topo invertidos — 1º
        // faturamento bruto desde o primeiro dia, 2º lucro líquido também
        // desde o primeiro dia (mesma conta do mês, sem o filtro de data),
        // atualiza sozinho assim que custo de produto for cadastrado.
        $salesRevenueAllTime = round((float) Order::query()->whereIn('status', self::REVENUE_STATUSES)->sum('subtotal'), 2);

        $productCostAllTime = round((float) Order::query()
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(order_items.quantity * products.cost_price), 0) as total')
            ->value('total'), 2);

        $marketplaceFeeAllTime = round((float) OrderChannelFee::query()->sum('fee_amount'), 2);

        $adSpendAllTime = round((float) ChannelAdSpend::query()->sum('spend'), 2);

        $netProfitAllTime = round($salesRevenueAllTime - $productCostAllTime - $marketplaceFeeAllTime - $adSpendAllTime - $flexCostAllTime, 2);

        // Valor de estoque — pedido explícito 2026-08-10: soma de todos os
        // produtos pelo valor pago (cost_price) vezes a quantidade em
        // estoque. Produto sem custo cadastrado ainda conta como 0 aqui
        // (mesmo aviso de dado incompleto que já existe pro resto da tela).
        $stockValue = round((float) Product::query()
            ->selectRaw('COALESCE(SUM(stock * COALESCE(cost_price, 0)), 0) as total')
            ->value('total'), 2);

        return Inertia::render('Admin/Financeiro/Dashboard', [
            'summary' => [
                'incomeMonth' => $incomeMonth,
                'expenseMonth' => $expenseMonth,
                // Pedido explícito 2026-08-09: "o lucro no mês sendo só o
                // líquido" — antes disso, este card mostrava
                // incomeMonth-expenseMonth (fluxo de caixa lançado à mão,
                // um conceito totalmente diferente de "a loja tá dando
                // lucro"). Agora é o mesmo netProfitMonth calculado abaixo
                // (receita real − custo − anúncio), pra ser literalmente a
                // métrica de "tá dando lucro ou não".
                'profitMonth' => $netProfitMonth,
                'salesRevenue' => (float) Order::query()->whereNot('status', Order::STATUS_CANCELLED)->sum('subtotal'),
                // Faturamento bruto/líquido desde o primeiro dia — pedido
                // explícito 2026-08-09 (cards do topo invertidos).
                'grossRevenueAllTime' => $salesRevenueAllTime,
                'netProfitAllTime' => $netProfitAllTime,
                'grossRevenueMonth' => $salesRevenueMonth,
                'netRevenueMonth' => $netRevenueMonth,
                'stockValue' => $stockValue,
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
                // Custo do Mercado Envios Flex do mês — pedido explícito
                // 2026-08-10, ver FlexDeliveryService.
                'flexCostMonth' => $flexCostMonth,
                'netProfitMonth' => $netProfitMonth,
                // Informativo — pedido explícito 2026-08-15. NÃO entra em
                // nenhuma soma/subtração do extrato (nem custo, nem
                // receita): é o frete que o comprador/canal pagou à
                // transportadora, dinheiro que nunca passa pelo vendedor.
                'shippingCostMonth' => $shippingCostMonth,
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
