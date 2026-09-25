<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Marketplace\Support\ContributionMargin;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        // Quando existe extrato financeiro importado, cada pedido já presente
        // no extrato usa product_net_sales como receita real da plataforma.
        // Pedidos do mesmo canal que ainda não apareceram no extrato continuam
        // entrando pelo subtotal local, senão o TikTok ficava com custo de todos
        // os pedidos e receita só dos 14 pedidos liquidados no relatório.
        $hasSettlementDetails = Schema::hasTable('marketplace_settlement_details');

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
        $salesRevenueMonthFromOrders = (float) Order::query()->nonPurchaseReturn()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $startOfMonth)
            ->when($hasSettlementDetails, function ($query) use ($startOfMonth) {
                $query->whereNotExists(function ($settlement) use ($startOfMonth) {
                    $settlement->selectRaw('1')
                        ->from('marketplace_settlement_details as settlement_check')
                        ->where('settlement_check.transaction_type', 'Pedido')
                        ->whereColumn('settlement_check.channel', 'orders.origin')
                        ->whereColumn('settlement_check.external_order_id', 'orders.external_order_id')
                        ->where('settlement_check.order_created_at', '>=', $startOfMonth->toDateString());
                });
            })
            ->sum(DB::raw(ContributionMargin::receitaSql()));

        $salesRevenueMonthFromSettlements = $hasSettlementDetails
            ? (float) DB::table('marketplace_settlement_details')
                ->where('transaction_type', 'Pedido')
                ->where('order_created_at', '>=', $startOfMonth->toDateString())
                ->sum('product_net_sales')
            : 0.0;

        $salesRevenueMonth = round($salesRevenueMonthFromOrders + $salesRevenueMonthFromSettlements, 2);

        $productCostMonth = round((float) Order::query()->nonPurchaseReturn()
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $startOfMonth)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(COALESCE(order_items.quantity * products.cost_price, order_items.manual_cost_price, 0)), 0) as total')
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
        $marketplaceFeeMonthFromOrders = round((float) OrderChannelFee::query()
            ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id')
            ->where('orders.created_at', '>=', $startOfMonth)
            ->when($hasSettlementDetails, function ($query) use ($startOfMonth) {
                $query->whereNotExists(function ($settlement) use ($startOfMonth) {
                    $settlement->selectRaw('1')
                        ->from('marketplace_settlement_details as settlement_check')
                        ->where('settlement_check.transaction_type', 'Pedido')
                        ->whereColumn('settlement_check.channel', 'orders.origin')
                        ->whereColumn('settlement_check.external_order_id', 'orders.external_order_id')
                        ->where('settlement_check.order_created_at', '>=', $startOfMonth->toDateString());
                });
            })
            ->sum('order_channel_fees.fee_amount'), 2);

        $settlementMarketplaceFeeMonth = $hasSettlementDetails
            ? round((float) DB::table('marketplace_settlement_details')
                ->where('transaction_type', 'Pedido')
                ->where('order_created_at', '>=', $startOfMonth->toDateString())
                ->selectRaw('COALESCE(SUM(ABS(platform_fees_taxes)), 0) as total')
                ->value('total'), 2)
            : 0.0;

        $marketplaceFeeMonth = round($marketplaceFeeMonthFromOrders + $settlementMarketplaceFeeMonth, 2);

        $baseAdSpendMonth = round((float) ChannelAdSpend::query()->where('date', '>=', $startOfMonth)->sum('spend'), 2);

        $settlementOrderExtraCostsMonth = $hasSettlementDetails
            ? DB::table('marketplace_settlement_details')
                ->where('transaction_type', 'Pedido')
                ->where('order_created_at', '>=', $startOfMonth->toDateString())
                ->selectRaw('COALESCE(SUM(CASE WHEN net_shipping_cost < 0 THEN ABS(net_shipping_cost) ELSE 0 END), 0) as shipping_cost,
                    COALESCE(SUM(ABS(affiliate_commissions)), 0) as affiliate_cost')
                ->first()
            : (object) ['shipping_cost' => 0, 'affiliate_cost' => 0];

        $settlementShippingCostMonth = round((float) ($settlementOrderExtraCostsMonth?->shipping_cost ?? 0), 2);
        $settlementAffiliateCostMonth = round((float) ($settlementOrderExtraCostsMonth?->affiliate_cost ?? 0), 2);

        // GMV Max / campanhas cobradas dentro do próprio extrato financeiro
        // do marketplace também são marketing. Entra junto com ChannelAdSpend
        // para o card "ADS + campanhas" não deixar TikTok de fora.
        $settlementAdSpendMonth = $hasSettlementDetails
            ? round((float) $this->dedupedSettlementAdSpendQuery($startOfMonth)
                ->selectRaw('COALESCE(SUM(spend), 0) as total')
                ->value('total'), 2)
            : 0.0;

        $adSpendMonth = round($baseAdSpendMonth + $settlementAdSpendMonth, 2);
        // TikTok Income já inclui afiliados dentro de "Taxas e impostos".
        // Mantemos afiliados como detalhe visual, mas não somamos de novo no
        // custo da plataforma para não derrubar margem/lucro em duplicidade.
        // Frete que a LOJA pagou nos Correios (pré-postagem, hoje Amazon via
        // Bling) — custo real da venda, pedido explícito 2026-09-25: "o
        // custo de frete dos Correios tem que vir da nossa pré-postagem".
        $correiosCostMonth = ContributionMargin::correios($startOfMonth);
        $platformCostsMonth = round($marketplaceFeeMonth + $flexCostMonth + $settlementShippingCostMonth + $correiosCostMonth, 2);
        $grossProfitMonth = round($salesRevenueMonth - $productCostMonth, 2);

        // Pedido explícito 2026-08-15: frete continua fora da conta de
        // faturamento/lucro (é pago pelo comprador/canal à transportadora,
        // nunca chega no vendedor — ver comentário em $salesRevenueMonth
        // acima), mas o usuário quer o valor visível/rastreável mesmo assim
        // — puramente informativo, nunca subtraído nem somado em nenhuma
        // conta de lucro. O dado em si já vive em orders.shipping_cost
        // desde sempre (ShopeeDriver::importOrder()); isso só soma pra
        // exibição.
        $shippingCostMonth = round((float) Order::query()->nonPurchaseReturn()
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
        $netProfitMonth = round($grossProfitMonth - $platformCostsMonth - $adSpendMonth, 2);
        $netProfitMarginMonth = $salesRevenueMonth > 0 ? round(($netProfitMonth / $salesRevenueMonth) * 100, 2) : 0.0;

        // Pedido explícito 2026-08-10: "faturamento liquido é o valor bruto
        // menos ads" — métrica distinta de lucro líquido (que também abate
        // o custo do material). Vai no lugar do card "Entradas no Mês", que
        // antes mostrava só o CashFlowEntry lançado à mão.
        $netRevenueMonth = round($salesRevenueMonth - $adSpendMonth, 2);

        // Pedido explícito 2026-08-09: cards do topo invertidos — 1º
        // faturamento bruto desde o primeiro dia, 2º lucro líquido também
        // desde o primeiro dia (mesma conta do mês, sem o filtro de data),
        // atualiza sozinho assim que custo de produto for cadastrado.
        $salesRevenueAllTimeFromOrders = (float) Order::query()->nonPurchaseReturn()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->when($hasSettlementDetails, function ($query) {
                $query->whereNotExists(function ($settlement) {
                    $settlement->selectRaw('1')
                        ->from('marketplace_settlement_details as settlement_check')
                        ->where('settlement_check.transaction_type', 'Pedido')
                        ->whereColumn('settlement_check.channel', 'orders.origin')
                        ->whereColumn('settlement_check.external_order_id', 'orders.external_order_id');
                });
            })
            ->sum(DB::raw(ContributionMargin::receitaSql()));

        $salesRevenueAllTimeFromSettlements = $hasSettlementDetails
            ? (float) DB::table('marketplace_settlement_details')
                ->where('transaction_type', 'Pedido')
                ->sum('product_net_sales')
            : 0.0;

        $salesRevenueAllTime = round($salesRevenueAllTimeFromOrders + $salesRevenueAllTimeFromSettlements, 2);

        $productCostAllTime = round((float) Order::query()->nonPurchaseReturn()
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(COALESCE(order_items.quantity * products.cost_price, order_items.manual_cost_price, 0)), 0) as total')
            ->value('total'), 2);

        $marketplaceFeeAllTimeFromOrders = round((float) OrderChannelFee::query()
            ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id')
            ->where(function ($query) {
                $query->whereNotIn('orders.origin', [Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_SALES_RETURN_INVOICE])
                    ->orWhereNull('orders.origin');
            })
            ->where(function ($query) {
                $query->whereNotIn('orders.fiscal_operation_type', ['purchase_return', 'sales_return'])
                    ->orWhereNull('orders.fiscal_operation_type');
            })
            ->when($hasSettlementDetails, function ($query) {
                $query->whereNotExists(function ($settlement) {
                    $settlement->selectRaw('1')
                        ->from('marketplace_settlement_details as settlement_check')
                        ->where('settlement_check.transaction_type', 'Pedido')
                        ->whereColumn('settlement_check.channel', 'orders.origin')
                        ->whereColumn('settlement_check.external_order_id', 'orders.external_order_id');
                });
            })
            ->sum('order_channel_fees.fee_amount'), 2);

        $settlementMarketplaceFeeAllTime = $hasSettlementDetails
            ? round((float) DB::table('marketplace_settlement_details')
                ->where('transaction_type', 'Pedido')
                ->selectRaw('COALESCE(SUM(ABS(platform_fees_taxes)), 0) as total')
                ->value('total'), 2)
            : 0.0;

        $marketplaceFeeAllTime = round($marketplaceFeeAllTimeFromOrders + $settlementMarketplaceFeeAllTime, 2);

        $settlementAdSpendAllTime = $hasSettlementDetails
            ? round((float) $this->dedupedSettlementAdSpendQuery(Carbon::create(2026, 1, 1))
                ->selectRaw('COALESCE(SUM(spend), 0) as total')
                ->value('total'), 2)
            : 0.0;

        $adSpendAllTime = round((float) ChannelAdSpend::query()->sum('spend') + $settlementAdSpendAllTime, 2);

        // Mesmos custos do mês (antes o frete do extrato e o dos Correios
        // ficavam de fora do "desde o início", e as duas contas divergiam).
        $settlementShippingCostAllTime = $hasSettlementDetails
            ? round((float) DB::table('marketplace_settlement_details')
                ->where('transaction_type', 'Pedido')
                ->selectRaw('COALESCE(SUM(CASE WHEN net_shipping_cost < 0 THEN ABS(net_shipping_cost) ELSE 0 END), 0) as total')
                ->value('total'), 2)
            : 0.0;
        $correiosCostAllTime = ContributionMargin::correios(null);
        $freteLojaAllTime = round($flexCostAllTime + $settlementShippingCostAllTime + $correiosCostAllTime, 2);

        $netProfitAllTime = round($salesRevenueAllTime - $productCostAllTime - $marketplaceFeeAllTime - $adSpendAllTime - $freteLojaAllTime, 2);
        $netRevenueAfterDeductionsAllTime = round($salesRevenueAllTime - $marketplaceFeeAllTime - $adSpendAllTime - $freteLojaAllTime, 2);
        $netRevenueAfterDeductionsMonth = round($salesRevenueMonth - $platformCostsMonth - $adSpendMonth, 2);
        $settlementSummary = $this->settlementSummary($startOfMonth);
        $marketplaceMetricsMonth = $this->marketplaceMetrics($startOfMonth, $flexCostMonth, $settlementSummary['month']['channels'] ?? []);

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
                'salesRevenue' => $salesRevenueAllTime,
                // Faturamento bruto/líquido desde o primeiro dia — pedido
                // explícito 2026-08-09 (cards do topo invertidos).
                'grossRevenueAllTime' => $salesRevenueAllTime,
                'netRevenueAfterDeductionsAllTime' => $netRevenueAfterDeductionsAllTime,
                'netProfitAllTime' => $netProfitAllTime,
                'grossRevenueMonth' => $salesRevenueMonth,
                'grossProfitMonth' => $grossProfitMonth,
                'netRevenueAfterDeductionsMonth' => $netRevenueAfterDeductionsMonth,
                'netRevenueMonth' => $netRevenueMonth,
                'platformCostsMonth' => $platformCostsMonth,
                'settlementShippingCostMonth' => $settlementShippingCostMonth,
                'settlementAffiliateCostMonth' => $settlementAffiliateCostMonth,
                'baseAdSpendMonth' => $baseAdSpendMonth,
                'settlementAdSpendMonth' => $settlementAdSpendMonth,
                'netProfitMarginMonth' => $netProfitMarginMonth,
                'marketplaceFeesAllTime' => $marketplaceFeeAllTime,
                'marketplaceFeesMonth' => $marketplaceFeeMonth,
                'adSpendAllTime' => $adSpendAllTime,
                'adSpendMonth' => $adSpendMonth,
                'productCostAllTime' => $productCostAllTime,
                'productCostMonth' => $productCostMonth,
                'flexCostAllTime' => $flexCostAllTime,
                'flexCostMonth' => $flexCostMonth,
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
                'platformCostsMonth' => $platformCostsMonth,
                'settlementShippingCostMonth' => $settlementShippingCostMonth,
                'settlementAffiliateCostMonth' => $settlementAffiliateCostMonth,
                'adSpendMonth' => $adSpendMonth,
                'baseAdSpendMonth' => $baseAdSpendMonth,
                'settlementAdSpendMonth' => $settlementAdSpendMonth,
                'grossProfitMonth' => $grossProfitMonth,
                'netProfitMarginMonth' => $netProfitMarginMonth,
                // Custo do Mercado Envios Flex do mês — pedido explícito
                // 2026-08-10, ver FlexDeliveryService.
                'flexCostMonth' => $flexCostMonth,
                // Pré-postagens dos Correios pagas pela loja (ver acima).
                'correiosCostMonth' => $correiosCostMonth,
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
                // Taxas reais por pedido quando o canal devolve ou quando um
                // extrato financeiro real foi importado/conciliado.
                'feeTrackedChannels' => ['mercado_livre', 'shopee', 'tiktok_shop', 'amazon'],
            ],
            'adSpendByChannel' => $this->adSpendByChannel($startOfMonth),
            'adSpendSeries' => $this->adSpendSeries($start14),
            'cashFlowSeries' => $this->cashFlowSeries($start14),
            'settlementSummary' => $settlementSummary,
            'marketplaceMetrics' => [
                'month' => $marketplaceMetricsMonth,
            ],
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


    /**
     * Métricas do mês por marketplace para comparação lado a lado.
     * Canais com extrato financeiro real usam o extrato como fonte primária;
     * os demais usam pedidos locais + taxas/ads locais disponíveis.
     *
     * @param array<int, array<string, mixed>> $settlementChannels
     */
    private function marketplaceMetrics(Carbon $startOfMonth, float $flexCostMonth, array $settlementChannels): array
    {
        $channels = [
            'shopee' => 'Shopee',
            'mercado_livre' => 'Mercado Livre',
            'tiktok_shop' => 'TikTok Shop',
            // Amazon com dado real desde 2026-09-25: pedidos e taxa pelo
            // Bling, frete pela pré-postagem dos Correios da loja.
            'amazon' => 'Amazon',
        ];

        $settlementsByChannel = collect($settlementChannels)->keyBy('channel');
        $settlementChannelKeys = $settlementsByChannel->keys()->all();

        $orders = Order::query()->nonPurchaseReturn()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $startOfMonth)
            ->selectRaw('origin as channel, COUNT(*) as orders_count, COALESCE(SUM('.ContributionMargin::receitaSql().'), 0) as revenue')
            ->groupBy('origin')
            ->get()
            ->keyBy('channel');

        // Pedidos do canal que ainda não apareceram em extrato continuam como
        // venda local. Isso mantém a mesma base do topo: extrato para pedidos
        // conciliados, subtotal local para pedidos pendentes de liquidação.
        $unsettledOrders = Schema::hasTable('marketplace_settlement_details')
            ? Order::query()->nonPurchaseReturn()
                ->whereIn('status', self::REVENUE_STATUSES)
                ->where('created_at', '>=', $startOfMonth)
                ->whereNotExists(function ($settlement) use ($startOfMonth) {
                    $settlement->selectRaw('1')
                        ->from('marketplace_settlement_details as settlement_check')
                        ->where('settlement_check.transaction_type', 'Pedido')
                        ->whereColumn('settlement_check.channel', 'orders.origin')
                        ->whereColumn('settlement_check.external_order_id', 'orders.external_order_id')
                        ->where('settlement_check.order_created_at', '>=', $startOfMonth->toDateString());
                })
                ->selectRaw('origin as channel, COUNT(*) as orders_count, COALESCE(SUM('.ContributionMargin::receitaSql().'), 0) as revenue')
                ->groupBy('origin')
                ->get()
                ->keyBy('channel')
            : $orders;

        $productCosts = DB::table('orders as o')
            ->leftJoin('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->whereIn('o.status', self::REVENUE_STATUSES)
            ->where(function ($query) {
                $query->whereNotIn('o.origin', [Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_SALES_RETURN_INVOICE])
                    ->orWhereNull('o.origin');
            })
            ->where(function ($query) {
                $query->whereNotIn('o.fiscal_operation_type', ['purchase_return', 'sales_return'])
                    ->orWhereNull('o.fiscal_operation_type');
            })
            ->where('o.created_at', '>=', $startOfMonth)
            ->selectRaw('o.origin as channel, COALESCE(SUM(COALESCE(oi.quantity * p.cost_price, oi.manual_cost_price, 0)), 0) as product_cost')
            ->groupBy('o.origin')
            ->get()
            ->keyBy('channel');

        $fees = OrderChannelFee::query()
            ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id')
            ->where('orders.created_at', '>=', $startOfMonth)
            ->where(function ($query) {
                $query->whereNotIn('orders.origin', [Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_SALES_RETURN_INVOICE])
                    ->orWhereNull('orders.origin');
            })
            ->where(function ($query) {
                $query->whereNotIn('orders.fiscal_operation_type', ['purchase_return', 'sales_return'])
                    ->orWhereNull('orders.fiscal_operation_type');
            })
            ->selectRaw('orders.origin as channel, COALESCE(SUM(order_channel_fees.fee_amount), 0) as fee_amount')
            ->groupBy('orders.origin')
            ->get()
            ->keyBy('channel');

        $unsettledFees = Schema::hasTable('marketplace_settlement_details')
            ? OrderChannelFee::query()
                ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id')
                ->where('orders.created_at', '>=', $startOfMonth)
                ->where(function ($query) {
                    $query->whereNotIn('orders.origin', [Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_SALES_RETURN_INVOICE])
                        ->orWhereNull('orders.origin');
                })
                ->where(function ($query) {
                    $query->where('orders.fiscal_operation_type', '!=', 'purchase_return')
                        ->orWhereNull('orders.fiscal_operation_type');
                })
                ->whereNotExists(function ($settlement) use ($startOfMonth) {
                    $settlement->selectRaw('1')
                        ->from('marketplace_settlement_details as settlement_check')
                        ->where('settlement_check.transaction_type', 'Pedido')
                        ->whereColumn('settlement_check.channel', 'orders.origin')
                        ->whereColumn('settlement_check.external_order_id', 'orders.external_order_id')
                        ->where('settlement_check.order_created_at', '>=', $startOfMonth->toDateString());
                })
                ->selectRaw('orders.origin as channel, COALESCE(SUM(order_channel_fees.fee_amount), 0) as fee_amount')
                ->groupBy('orders.origin')
                ->get()
                ->keyBy('channel')
            : $fees;

        $ads = ChannelAdSpend::query()
            ->where('date', '>=', $startOfMonth)
            ->selectRaw('channel, COALESCE(SUM(spend), 0) as spend')
            ->groupBy('channel')
            ->get()
            ->keyBy('channel');

        $settlementAds = Schema::hasTable('marketplace_settlement_details')
            ? $this->dedupedSettlementAdSpendQuery($startOfMonth)
                ->selectRaw('channel, COALESCE(SUM(spend), 0) as spend')
                ->groupBy('channel')
                ->get()
                ->keyBy('channel')
            : collect();

        $correiosPorCanal = ContributionMargin::correios($startOfMonth, null, porCanal: true);

        return collect($channels)->map(function ($label, $channel) use ($orders, $unsettledOrders, $productCosts, $fees, $unsettledFees, $ads, $settlementAds, $settlementsByChannel, $settlementChannelKeys, $flexCostMonth, $correiosPorCanal) {
            $settlement = $settlementsByChannel->get($channel);
            $hasSettlement = $settlement !== null;
            $orderRow = $orders->get($channel);
            $localPendingRow = $unsettledOrders->get($channel);

            $source = 'Sem dados no mês';

            if ($hasSettlement) {
                $settlementRevenue = round((float) ($settlement['productNetSales'] ?? 0), 2);
                $localPendingRevenue = round((float) ($localPendingRow?->revenue ?? 0), 2);
                $revenue = round($settlementRevenue + $localPendingRevenue, 2);
                $ordersCount = (int) ($settlement['uniqueOrders'] ?? 0) + (int) ($localPendingRow?->orders_count ?? 0);
                // O topo financeiro usa o custo local do mês por origem. Mantém
                // a mesma base aqui para o split por marketplace não divergir.
                $productCost = round((float) ($productCosts->get($channel)?->product_cost ?? 0), 2);
                $shippingCost = max(0, -1 * (float) ($settlement['netShippingImpact'] ?? 0));
                $platformCosts = round(
                    (float) ($settlement['platformFeesTaxes'] ?? 0)
                    + $shippingCost
                    + (float) ($unsettledFees->get($channel)?->fee_amount ?? 0)
                    + (float) ($correiosPorCanal[$channel] ?? 0),
                    2
                );
                $adSpend = round((float) ($settlementAds->get($channel)?->spend ?? 0) + (float) ($ads->get($channel)?->spend ?? 0), 2);
                $source = (float) ($localPendingRow?->revenue ?? 0) > 0 ? 'Extrato + locais pendentes' : 'Extrato';
            } else {
                $revenue = round((float) ($orderRow?->revenue ?? 0), 2);
                $ordersCount = (int) ($orderRow?->orders_count ?? 0);
                $productCost = round((float) ($productCosts->get($channel)?->product_cost ?? 0), 2);
                $adSpend = round((float) ($ads->get($channel)?->spend ?? 0), 2);
                $platformCosts = round((float) ($fees->get($channel)?->fee_amount ?? 0) + (float) ($correiosPorCanal[$channel] ?? 0), 2);

                // O Flex é custo operacional do Mercado Livre, então fica no card dele.
                if ($channel === 'mercado_livre') {
                    $platformCosts = round($platformCosts + $flexCostMonth, 2);
                }

                $source = ($ordersCount > 0 || $revenue != 0.0 || $adSpend != 0.0 || $platformCosts != 0.0 || $productCost != 0.0)
                    ? 'Pedidos locais/API'
                    : 'Sem dados no mês';
            }

            $grossProfit = round($revenue - $productCost, 2);
            $netProfit = round($grossProfit - $adSpend - $platformCosts, 2);
            $margin = $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0.0;

            $isEmpty = $ordersCount === 0
                && abs((float) $revenue) <= 0.0
                && abs((float) $adSpend) <= 0.0
                && abs((float) $platformCosts) <= 0.0
                && abs((float) $productCost) <= 0.0
                && abs((float) $netProfit) <= 0.0;

            return [
                'channel' => $channel,
                'label' => $label,
                'source' => $source,
                'ordersCount' => $ordersCount,
                'grossRevenue' => $revenue,
                'adSpend' => $adSpend,
                'platformCosts' => $platformCosts,
                'productCost' => $productCost,
                'grossProfit' => $grossProfit,
                'netProfit' => $netProfit,
                'netMargin' => $margin,
                'isEmpty' => $isEmpty,
            ];
        })->values()->all();
    }

    /**
     * Extratos financeiros reais importados dos marketplaces (ex.: relatório
     * Income/Settlement TikTok Shop). Diferente de orders.*, isto carrega o
     * que a plataforma liquidou de fato: descontos, frete líquido, taxas,
     * ajustes e valor a receber. O custo de produto só entra quando o pedido
     * do extrato foi conciliado com um pedido existente do KazaKora.
     */
    private function settlementSummary(Carbon $startOfMonth): array
    {
        if (! Schema::hasTable('marketplace_settlement_details')) {
            return [
                'available' => false,
                'month' => ['channels' => [], 'totals' => []],
                'allTime' => ['channels' => [], 'totals' => []],
            ];
        }

        return [
            'available' => true,
            'month' => $this->settlementSummaryForPeriod($startOfMonth),
            'allTime' => $this->settlementSummaryForPeriod(),
        ];
    }

    private function settlementSummaryForPeriod(?Carbon $start = null): array
    {
        $base = DB::table('marketplace_settlement_details');

        if ($start) {
            $base->where('order_created_at', '>=', $start->toDateString());
        }

        $rows = (clone $base)
            ->selectRaw('channel,
                COUNT(*) as line_items,
                COUNT(DISTINCT CASE WHEN transaction_type = \'Pedido\' THEN NULLIF(external_order_id, \'/\') END) as unique_orders,
                COALESCE(SUM(payout_amount), 0) as payout_amount,
                COALESCE(SUM(product_net_sales), 0) as product_net_sales,
                COALESCE(SUM(item_subtotal_before_discounts), 0) as item_subtotal_before_discounts,
                COALESCE(SUM(seller_discounts), 0) as seller_discounts,
                COALESCE(SUM(COALESCE(platform_product_discounts, 0)), 0) as platform_product_discounts,
                COALESCE(SUM(COALESCE(platform_coupon_discounts, 0)), 0) as platform_coupon_discounts,
                COALESCE(SUM(COALESCE(platform_coupon_discount_refunds, 0)), 0) as platform_coupon_discount_refunds,
                COALESCE(SUM(COALESCE(platform_shipping_discounts, 0)), 0) as platform_shipping_discounts,
                COALESCE(SUM(product_refunds), 0) as product_refunds,
                COALESCE(SUM(net_shipping_cost), 0) as net_shipping_cost,
                COALESCE(SUM(platform_fees_taxes), 0) as platform_fees_taxes,
                COALESCE(SUM(affiliate_commissions), 0) as affiliate_commissions,
                COALESCE(SUM(gmv_max_ad_fee), 0) as gmv_max_ad_fee,
                COALESCE(SUM(adjustment_amount), 0) as adjustment_amount,
                COALESCE(SUM(CASE WHEN status = \'Pagos\' THEN payout_amount ELSE 0 END), 0) as paid_payout_amount,
                COALESCE(SUM(CASE WHEN status <> \'Pagos\' THEN payout_amount ELSE 0 END), 0) as pending_payout_amount')
            ->groupBy('channel')
            ->get();

        $externalIds = (clone $base)
            ->where('transaction_type', 'Pedido')
            ->whereNotNull('external_order_id')
            ->where('external_order_id', '<>', '/')
            ->distinct()
            ->pluck('external_order_id')
            ->all();

        $costs = collect();
        $matchedSettlements = collect();

        if ($externalIds) {
            $costs = DB::table('orders as o')
                ->leftJoin('order_items as oi', 'oi.order_id', '=', 'o.id')
                ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
                ->whereIn('o.external_order_id', $externalIds)
                ->selectRaw('o.origin as channel,
                    COUNT(DISTINCT o.id) as matched_orders,
                    COALESCE(SUM(COALESCE(oi.quantity * p.cost_price, oi.manual_cost_price, 0)), 0) as product_cost,
                    SUM(CASE WHEN oi.id IS NOT NULL AND COALESCE(oi.manual_cost_price, p.cost_price) IS NULL THEN 1 ELSE 0 END) as cost_missing_items')
                ->groupBy('o.origin')
                ->get()
                ->keyBy('channel');

            $matchedSettlements = DB::table('marketplace_settlement_details as s')
                ->join('orders as o', function ($join) {
                    $join->on('o.external_order_id', '=', 's.external_order_id')
                        ->on('o.origin', '=', 's.channel');
                })
                ->where('s.transaction_type', 'Pedido')
                ->when($start, fn ($query) => $query->where('s.order_created_at', '>=', $start->toDateString()))
                ->selectRaw('s.channel,
                    COALESCE(SUM(s.product_net_sales), 0) as matched_product_net_sales,
                    COALESCE(SUM(s.payout_amount), 0) as matched_payout_amount')
                ->groupBy('s.channel')
                ->get()
                ->keyBy('channel');
        }

        $channels = $rows->map(function ($row) use ($costs, $matchedSettlements) {
            $cost = $costs->get($row->channel);
            $productCost = round((float) ($cost?->product_cost ?? 0), 2);
            $productNetSales = round((float) $row->product_net_sales, 2);
            $payout = round((float) $row->payout_amount, 2);
            $matched = $matchedSettlements->get($row->channel);
            $matchedProductNetSales = round((float) ($matched?->matched_product_net_sales ?? 0), 2);
            $matchedPayout = round((float) ($matched?->matched_payout_amount ?? 0), 2);
            $matchedOrders = (int) ($cost?->matched_orders ?? 0);
            $uniqueOrders = (int) $row->unique_orders;

            return [
                'channel' => $row->channel,
                'lineItems' => (int) $row->line_items,
                'uniqueOrders' => $uniqueOrders,
                'matchedOrders' => $matchedOrders,
                'missingOrders' => max($uniqueOrders - $matchedOrders, 0),
                'payoutAmount' => $payout,
                'productNetSales' => $productNetSales,
                'itemSubtotalBeforeDiscounts' => round((float) $row->item_subtotal_before_discounts, 2),
                'sellerDiscounts' => round(abs((float) $row->seller_discounts), 2),
                'platformProductDiscounts' => round(abs((float) $row->platform_product_discounts), 2),
                'platformCouponDiscounts' => round(abs((float) $row->platform_coupon_discounts), 2),
                'platformCouponDiscountRefunds' => round(abs((float) $row->platform_coupon_discount_refunds), 2),
                'platformShippingDiscounts' => round(abs((float) $row->platform_shipping_discounts), 2),
                'productRefunds' => round(abs((float) $row->product_refunds), 2),
                'netShippingImpact' => round((float) $row->net_shipping_cost, 2),
                'platformFeesTaxes' => round(abs((float) $row->platform_fees_taxes), 2),
                'affiliateCommissions' => round(abs((float) $row->affiliate_commissions), 2),
                'gmvMaxAdFee' => round(abs((float) $row->gmv_max_ad_fee), 2),
                'adjustmentAmount' => round((float) $row->adjustment_amount, 2),
                'paidPayoutAmount' => round((float) $row->paid_payout_amount, 2),
                'pendingPayoutAmount' => round((float) $row->pending_payout_amount, 2),
                'productCostMatched' => $productCost,
                'matchedProductNetSales' => $matchedProductNetSales,
                'matchedPayoutAmount' => $matchedPayout,
                'costMissingItems' => (int) ($cost?->cost_missing_items ?? 0),
                'grossProfitKnown' => round($matchedProductNetSales - $productCost, 2),
                'netProfitKnown' => round($matchedPayout - $productCost, 2),
            ];
        })->values();

        $totals = [
            'lineItems' => $channels->sum('lineItems'),
            'uniqueOrders' => $channels->sum('uniqueOrders'),
            'matchedOrders' => $channels->sum('matchedOrders'),
            'missingOrders' => $channels->sum('missingOrders'),
            'payoutAmount' => round($channels->sum('payoutAmount'), 2),
            'productNetSales' => round($channels->sum('productNetSales'), 2),
            'itemSubtotalBeforeDiscounts' => round($channels->sum('itemSubtotalBeforeDiscounts'), 2),
            'sellerDiscounts' => round($channels->sum('sellerDiscounts'), 2),
            'platformProductDiscounts' => round($channels->sum('platformProductDiscounts'), 2),
            'platformCouponDiscounts' => round($channels->sum('platformCouponDiscounts'), 2),
            'platformCouponDiscountRefunds' => round($channels->sum('platformCouponDiscountRefunds'), 2),
            'platformShippingDiscounts' => round($channels->sum('platformShippingDiscounts'), 2),
            'productRefunds' => round($channels->sum('productRefunds'), 2),
            'netShippingImpact' => round($channels->sum('netShippingImpact'), 2),
            'platformFeesTaxes' => round($channels->sum('platformFeesTaxes'), 2),
            'affiliateCommissions' => round($channels->sum('affiliateCommissions'), 2),
            'gmvMaxAdFee' => round($channels->sum('gmvMaxAdFee'), 2),
            'adjustmentAmount' => round($channels->sum('adjustmentAmount'), 2),
            'paidPayoutAmount' => round($channels->sum('paidPayoutAmount'), 2),
            'pendingPayoutAmount' => round($channels->sum('pendingPayoutAmount'), 2),
            'productCostMatched' => round($channels->sum('productCostMatched'), 2),
            'matchedProductNetSales' => round($channels->sum('matchedProductNetSales'), 2),
            'matchedPayoutAmount' => round($channels->sum('matchedPayoutAmount'), 2),
            'grossProfitKnown' => round($channels->sum('grossProfitKnown'), 2),
            'netProfitKnown' => round($channels->sum('netProfitKnown'), 2),
        ];

        return [
            'channels' => $channels->all(),
            'totals' => $totals,
        ];
    }

    private function dedupedSettlementAdSpendQuery(Carbon $start)
    {
        $rows = DB::table('marketplace_settlement_details')
            ->where('settlement_date', '>=', $start->toDateString())
            ->where(function ($query) {
                $query->where('transaction_type', 'like', '%GMV%')
                    ->orWhere('transaction_type', 'like', '%anúncio%')
                    ->orWhere('transaction_type', 'like', '%anuncio%')
                    ->orWhere('gmv_max_ad_fee', '<>', 0);
            })
            // Dedup por identidade financeira do lançamento, não por arquivo:
            // o mesmo Excel TikTok pode ser importado mais de uma vez com
            // source_file diferente. Além disso, se GMV Max vier preenchido em
            // duas colunas na mesma linha, usa só uma delas.
            ->selectRaw('channel, statement_id, external_order_id, transaction_type, settlement_date,
                MAX(CASE WHEN ABS(COALESCE(gmv_max_ad_fee, 0)) > 0 THEN ABS(gmv_max_ad_fee) ELSE ABS(adjustment_amount) END) as spend')
            ->groupBy('channel', 'statement_id', 'external_order_id', 'transaction_type', 'settlement_date');

        return DB::query()->fromSub($rows, 'deduped_settlement_ads');
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
