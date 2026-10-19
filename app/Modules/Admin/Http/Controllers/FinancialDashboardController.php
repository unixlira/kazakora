<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\PrevisaoDeCaixa;
use App\Modules\Financeiro\Support\RadarDeAds;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resultado e margem (/admin/dashboard-financeiro), reestruturado em
 * 2026-10-19 pela proposta do Jonathan aprovada pelo Lira: período
 * escolhido na URL, "Quanto sobrou", "Para onde foi o dinheiro" (um extrato
 * só), lucro por canal em tabela, anúncios com o que trouxeram, dinheiro
 * para receber e, recolhida, a conferência com os extratos.
 *
 * Todo número de lucro vem da conta oficial (ContributionMargin::periodo e
 * porCanal), a mesma do painel, do KoraSync, do Fluxo de Caixa e do e-mail
 * das 23h50. Saiu daqui a conta paralela antiga (bruto desde o início,
 * "lucro líquido conhecido" do extrato, frete informativo, entradas x
 * saídas e projeção por quinzenas).
 */
class FinancialDashboardController extends Controller
{
    /** Canais que sempre aparecem (com ou sem venda), na ordem do resto do admin. */
    private const CANAIS = [
        Order::ORIGIN_SHOPEE => 'Shopee',
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_AMAZON => 'Amazon',
        Order::ORIGIN_STORE => 'KazaKora (site)',
        Order::SITE_FERRAKORA => 'FerraKora',
        Order::SITE_BEAUTYKORA => 'Beauty Kora',
    ];

    /** Canais em que pedido sem taxa real do canal deixa a margem estimada. */
    private const CANAIS_COM_TAXA = [Order::ORIGIN_SHOPEE, Order::ORIGIN_MERCADO_LIVRE, Order::ORIGIN_TIKTOK_SHOP, Order::ORIGIN_AMAZON];

    public function index(Request $request, ContributionMargin $margem, PrevisaoDeCaixa $previsao, RadarDeAds $radar): Response
    {
        $f = $this->filtro($request);
        $de = $f['de'];
        $fim = $f['ate']->copy()->addDay();

        $conta = $margem->periodo($de, $fim);
        $porCanal = $margem->porCanal($de, $fim);
        $qualidade = ContributionMargin::qualidadeTaxas($de, $fim);
        $semTaxaReal = $this->pedidosSemTaxaReal($de, $fim);

        return Inertia::render('Admin/Financeiro/Dashboard', [
            'filtro' => $f['publico'],
            'conta' => $conta + [
                'pedidos' => (int) array_sum(array_column($porCanal, 'pedidos')),
                'aliquota' => ContributionMargin::aliquotaSimples(),
            ],
            'confianca' => $this->confianca($de, $fim, $f, $qualidade, $semTaxaReal),
            'canais' => $this->canais($porCanal, $semTaxaReal),
            'anuncios' => fn () => $this->anuncios($de, $f['ate'], $porCanal, $radar),
            'receber' => fn () => $this->receber($previsao),
            'conferencia' => fn () => $this->conferencia($de, $fim),
            'radarAds' => fn () => $radar->resumo(),
        ]);
    }

    /**
     * Período em America/Sao_Paulo: este mês (padrão), mês passado, desde o
     * início (primeira venda) ou datas escolhidas.
     *
     * @return array{de: Carbon, ate: Carbon, publico: array<string, mixed>}
     */
    private function filtro(Request $request): array
    {
        $hoje = Carbon::today();
        $data = function (mixed $valor): ?Carbon {
            if (! is_string($valor) || $valor === '') {
                return null;
            }
            try {
                return Carbon::createFromFormat('!Y-m-d', substr($valor, 0, 10));
            } catch (\Throwable) {
                return null;
            }
        };

        $primeira = DB::table('orders')->whereIn('status', ContributionMargin::REVENUE_STATUSES)->min('created_at');
        $inicio = $primeira ? Carbon::parse($primeira)->startOfDay() : $hoje->copy()->startOfMonth();

        $periodo = (string) $request->query('periodo', '');
        $deQuery = $data($request->query('de'));
        $ateQuery = $data($request->query('ate'));
        if ($periodo === '' && ($deQuery || $ateQuery)) {
            $periodo = 'datas';
        }

        [$de, $ate] = match ($periodo) {
            'mes_passado' => [$hoje->copy()->startOfMonth()->subMonthNoOverflow(), $hoje->copy()->startOfMonth()->subDay()],
            'inicio' => [$inicio->copy(), $hoje->copy()],
            'datas' => [$deQuery ?? $hoje->copy()->startOfMonth(), $ateQuery ?? $hoje->copy()],
            default => [$hoje->copy()->startOfMonth(), $hoje->copy()],
        };
        if (! in_array($periodo, ['mes_passado', 'inicio', 'datas'], true)) {
            $periodo = 'mes';
        }
        if ($de->gt($ate)) {
            [$de, $ate] = [$ate, $de];
        }

        return [
            'de' => $de,
            'ate' => $ate,
            'publico' => [
                'periodo' => $periodo,
                'de' => $de->toDateString(),
                'ate' => $ate->toDateString(),
                'hoje' => $hoje->toDateString(),
                'inicio' => $inicio->toDateString(),
            ],
        ];
    }

    /**
     * Avisos de confiança do bloco "Quanto sobrou": itens vendidos sem custo
     * e pedidos com taxa estimada (ou sem taxa do canal ainda).
     *
     * @param  array<string, array{real: float, estimada: float, pedidos_real: int, pedidos_estimada: int}>  $qualidade
     * @param  array<string, int>  $semTaxaReal
     * @return array<string, mixed>
     */
    private function confianca(Carbon $de, Carbon $fim, array $f, array $qualidade, array $semTaxaReal): array
    {
        $itensSemCusto = (int) DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $de)
            ->where('orders.created_at', '<', $fim)
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q))
            ->whereNull('products.cost_price')
            ->whereNull('order_items.manual_cost_price')
            ->count();

        $comTaxaEstimada = array_filter($semTaxaReal);

        return [
            'itensSemCusto' => $itensSemCusto,
            'produtosAtivos' => Product::query()->where('is_active', true)->count(),
            'produtosAtivosSemCusto' => Product::query()->where('is_active', true)->whereNull('cost_price')->count(),
            'linkSemCusto' => '/admin/fluxo-de-caixa?'.http_build_query([
                'aba' => 'vendas', 'sem_custo' => 1, 'de' => $f['publico']['de'], 'ate' => $f['publico']['ate'],
            ]),
            'taxaEstimada' => [
                'pedidos' => (int) array_sum($comTaxaEstimada),
                'valor' => round((float) array_sum(array_column($qualidade, 'estimada')), 2),
                'canais' => array_values(array_map(fn ($c) => self::CANAIS[$c] ?? $c, array_keys($comTaxaEstimada))),
            ],
        ];
    }

    /**
     * Pedidos de marketplace sem a taxa real do canal (só estimada ou
     * nenhuma): a margem desse canal ainda vai mudar.
     *
     * @return array<string, int>
     */
    private function pedidosSemTaxaReal(Carbon $de, Carbon $fim): array
    {
        return Order::query()->vendaEfetiva()
            ->whereIn('status', ContributionMargin::REVENUE_STATUSES)
            ->where('created_at', '>=', $de)
            ->where('created_at', '<', $fim)
            ->whereIn('origin', self::CANAIS_COM_TAXA)
            ->whereDoesntHave('channelFee', fn ($taxa) => $taxa->where('fee_amount', '>', 0)->where('source', '!=', OrderChannelFee::SOURCE_ESTIMATE))
            ->selectRaw('origin, COUNT(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Tabela "Lucro por canal": conta oficial de porCanal, do maior lucro
     * para o menor; canal fixo sem venda vai para a linha "Sem vendas".
     *
     * @param  array<string, array<string, float|int>>  $porCanal
     * @param  array<string, int>  $semTaxaReal
     * @return array{linhas: list<array<string, mixed>>, semVendas: list<string>}
     */
    private function canais(array $porCanal, array $semTaxaReal): array
    {
        $linhas = [];
        $semVendas = [];
        foreach (array_unique([...array_keys(self::CANAIS), ...array_keys($porCanal)]) as $canal) {
            $l = array_merge(ContributionMargin::contaVazia(), $porCanal[$canal] ?? []);
            $nome = self::CANAIS[$canal] ?? $canal;
            if ((int) $l['pedidos'] === 0 && (float) $l['receita'] == 0.0 && (float) $l['ads'] == 0.0) {
                if (isset(self::CANAIS[$canal]) && ! in_array($canal, [Order::SITE_FERRAKORA, Order::SITE_BEAUTYKORA], true)) {
                    $semVendas[] = $nome;
                }

                continue;
            }
            $linhas[] = [
                'canal' => $canal,
                'nome' => $nome,
                'pedidos' => (int) $l['pedidos'],
                'receita' => (float) $l['receita'],
                'ads' => (float) $l['ads'],
                'lucro' => (float) $l['lucro'],
                'margemPct' => (float) $l['receita'] > 0 ? round((float) $l['lucro'] / (float) $l['receita'] * 100, 2) : null,
                'pedidosTaxaEstimada' => (int) ($semTaxaReal[$canal] ?? 0),
            ];
        }

        usort($linhas, fn ($a, $b) => $b['lucro'] <=> $a['lucro']);

        return ['linhas' => $linhas, 'semVendas' => $semVendas];
    }

    /**
     * Bloco "Anúncios: quanto custou e o que trouxe". Gasto = o ADS da conta
     * oficial (inclui o GMV Max do TikTok, que vem do extrato). Vendas
     * atribuídas só existem onde o canal informa (Shopee Ads; Mercado Ads
     * quando a API devolve): sem dado fica null, nunca zero. Meta Ads vem da
     * Marketing API e NÃO entra na conta oficial do lucro do site.
     *
     * @param  array<string, array<string, float|int>>  $porCanal
     * @return array<string, mixed>
     */
    private function anuncios(Carbon $de, Carbon $ate, array $porCanal, RadarDeAds $radar): array
    {
        $atribuido = ChannelAdSpend::query()
            ->where('date', '>=', $de->toDateString())
            ->where('date', '<=', $ate->toDateString())
            ->selectRaw('channel, SUM(spend) as spend, SUM(attributed_orders) as pedidos, SUM(attributed_gmv) as vendas')
            ->groupBy('channel')
            ->get()
            ->keyBy('channel');

        $linhas = [];
        foreach ([Order::ORIGIN_SHOPEE, Order::ORIGIN_MERCADO_LIVRE, Order::ORIGIN_TIKTOK_SHOP, Order::ORIGIN_AMAZON] as $canal) {
            $gasto = (float) ($porCanal[$canal]['ads'] ?? 0);
            if ($canal === Order::ORIGIN_AMAZON && $gasto <= 0) {
                continue;
            }
            $a = $atribuido->get($canal);
            $vendas = $a && ((float) $a->vendas > 0 || (int) $a->pedidos > 0) ? round((float) $a->vendas, 2) : null;
            $linhas[] = [
                'canal' => $canal,
                'nome' => self::CANAIS[$canal],
                'gasto' => round($gasto, 2),
                'vendas' => $vendas,
                'roas' => $vendas !== null && $gasto > 0 ? round($vendas / $gasto, 2) : null,
                'lucro' => isset($porCanal[$canal]) ? (float) $porCanal[$canal]['lucro'] : null,
                'nota' => $canal === Order::ORIGIN_TIKTOK_SHOP ? 'GMV Max, pelo demonstrativo do TikTok' : null,
                'link' => RadarDeAds::LINKS[$canal],
            ];
        }

        $meta = $radar->metaNoPeriodo($de, $ate);
        $linhas[] = [
            'canal' => RadarDeAds::META,
            'nome' => 'Meta Ads (site)',
            'gasto' => $meta['gasto'] ?? null,
            'vendas' => $meta !== null && $meta['vendas'] > 0 ? $meta['vendas'] : null,
            'roas' => $meta !== null && $meta['gasto'] > 0 && $meta['vendas'] > 0 ? round($meta['vendas'] / $meta['gasto'], 2) : null,
            'lucro' => isset($porCanal[Order::ORIGIN_STORE]) ? (float) $porCanal[Order::ORIGIN_STORE]['lucro'] : null,
            'nota' => $meta === null ? 'gasto não disponível agora' : 'vendas pelo pixel; o gasto da Meta ainda não entra no lucro do site',
            'link' => RadarDeAds::LINKS[RadarDeAds::META],
        ];

        return [
            'linhas' => $linhas,
            'serie' => $this->serieAds(Carbon::today()->subDays(13)),
        ];
    }

    /** Gasto diário dos últimos 14 dias (Shopee Ads e Mercado Ads, os que sincronizam por dia). */
    private function serieAds(Carbon $start): array
    {
        $rows = ChannelAdSpend::query()
            ->selectRaw('date, channel, SUM(spend) as spend')
            ->where('date', '>=', $start->toDateString())
            ->groupBy('date', 'channel')
            ->get();

        $series = [];
        for ($date = $start->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $key = $date->toDateString();
            $dia = $rows->filter(fn ($r) => Carbon::parse($r->date)->toDateString() === $key);
            $series[] = [
                'date' => $key,
                'shopee' => round((float) ($dia->firstWhere('channel', 'shopee')?->spend ?? 0), 2),
                'mercado_livre' => round((float) ($dia->firstWhere('channel', 'mercado_livre')?->spend ?? 0), 2),
            ];
        }

        return $series;
    }

    /**
     * "Dinheiro para receber": disponível para saque agora e o que os
     * extratos dizem que ainda vai cair (PrevisaoDeCaixa::aLiberar).
     *
     * @return array<string, mixed>
     */
    private function receber(PrevisaoDeCaixa $previsao): array
    {
        $carteiras = $previsao->walletBalances();

        return [
            'disponivel' => [
                [
                    'canal' => 'shopee', 'nome' => 'Shopee', 'valor' => $carteiras['shopee'],
                    'quando' => $carteiras['shopee_consultado_em'] ?? null, 'motivo' => $carteiras['shopee_erro'] ?? null,
                    'atualizar' => true,
                ],
                [
                    'canal' => 'mercado_pago', 'nome' => 'Mercado Pago (ML + site)', 'valor' => $carteiras['mercado_livre'],
                    'quando' => $carteiras['mercado_livre_as_of'] ?? null,
                    'motivo' => $carteiras['mercado_livre'] === null ? 'Saldo ainda não sincronizado.' : null,
                    'atualizar' => false,
                ],
                ['canal' => 'tiktok_shop', 'nome' => 'TikTok Shop', 'valor' => null, 'quando' => null, 'motivo' => 'Saldo não disponível pela integração.', 'atualizar' => false],
                ['canal' => 'amazon', 'nome' => 'Amazon', 'valor' => null, 'quando' => null, 'motivo' => 'Saldo não disponível pela integração.', 'atualizar' => false],
            ],
            'aLiberar' => $previsao->aLiberar(),
        ];
    }

    /**
     * "Conferência com os extratos dos canais" (era "Extratos reais de
     * Marketplace"), agora pelo período escolhido. Mostra o que o canal
     * liquidou; o lucro é sempre o da conta oficial, não sai daqui.
     *
     * @return array{channels: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function conferencia(Carbon $de, Carbon $fim): array
    {
        if (! Schema::hasTable('marketplace_settlement_details')) {
            return ['channels' => [], 'totals' => []];
        }

        $base = fn () => DB::table('marketplace_settlement_details')
            ->where('order_created_at', '>=', $de->toDateString())
            ->where('order_created_at', '<', $fim->toDateString());

        $rows = $base()
            ->selectRaw("channel,
                COUNT(*) as line_items,
                COUNT(DISTINCT CASE WHEN transaction_type IN ('Pedido', 'Order') THEN NULLIF(external_order_id, '/') END) as unique_orders,
                COALESCE(SUM(payout_amount), 0) as payout_amount,
                COALESCE(SUM(product_net_sales), 0) as product_net_sales,
                COALESCE(SUM(item_subtotal_before_discounts), 0) as item_subtotal_before_discounts,
                COALESCE(SUM(seller_discounts), 0) as seller_discounts,
                COALESCE(SUM(COALESCE(platform_product_discounts, 0)), 0) as platform_product_discounts,
                COALESCE(SUM(COALESCE(platform_coupon_discounts, 0)), 0) as platform_coupon_discounts,
                COALESCE(SUM(net_shipping_cost), 0) as net_shipping_cost,
                COALESCE(SUM(platform_fees_taxes), 0) as platform_fees_taxes,
                COALESCE(SUM(adjustment_amount), 0) as adjustment_amount,
                COALESCE(SUM(CASE WHEN status IN ('".implode("','", PrevisaoDeCaixa::STATUS_PAGOS)."') THEN payout_amount ELSE 0 END), 0) as paid_payout_amount")
            ->groupBy('channel')
            ->get();

        $conciliados = $base()
            ->join('orders as o', function ($join) {
                $join->on('o.external_order_id', '=', 'marketplace_settlement_details.external_order_id')
                    ->on('o.origin', '=', 'marketplace_settlement_details.channel');
            })
            ->whereIn('marketplace_settlement_details.transaction_type', ['Pedido', 'Order'])
            ->selectRaw('marketplace_settlement_details.channel as channel, COUNT(DISTINCT o.id) as pedidos')
            ->groupBy('marketplace_settlement_details.channel')
            ->pluck('pedidos', 'channel');

        $channels = $rows->map(function ($r) use ($conciliados) {
            $payout = round((float) $r->payout_amount, 2);
            $pago = round((float) $r->paid_payout_amount, 2);
            $pedidos = (int) $r->unique_orders;
            $ok = (int) ($conciliados[$r->channel] ?? 0);

            return [
                'channel' => $r->channel,
                'label' => self::CANAIS[$r->channel] ?? $r->channel,
                'lineItems' => (int) $r->line_items,
                'uniqueOrders' => $pedidos,
                'matchedOrders' => $ok,
                'missingOrders' => max($pedidos - $ok, 0),
                'itemSubtotalBeforeDiscounts' => round((float) $r->item_subtotal_before_discounts, 2),
                'productNetSales' => round((float) $r->product_net_sales, 2),
                'sellerDiscounts' => round(abs((float) $r->seller_discounts), 2),
                'platformDiscounts' => round(abs((float) $r->platform_product_discounts) + abs((float) $r->platform_coupon_discounts), 2),
                'platformFeesTaxes' => round(abs((float) $r->platform_fees_taxes), 2),
                'netShippingImpact' => round((float) $r->net_shipping_cost, 2),
                'adjustmentAmount' => round((float) $r->adjustment_amount, 2),
                'payoutAmount' => $payout,
                'paidPayoutAmount' => $pago,
                'pendingPayoutAmount' => round($payout - $pago, 2),
            ];
        })->sortByDesc('productNetSales')->values();

        return [
            'channels' => $channels->all(),
            'totals' => [
                'lineItems' => $channels->sum('lineItems'),
                'uniqueOrders' => $channels->sum('uniqueOrders'),
                'payoutAmount' => round($channels->sum('payoutAmount'), 2),
                'paidPayoutAmount' => round($channels->sum('paidPayoutAmount'), 2),
                'pendingPayoutAmount' => round($channels->sum('pendingPayoutAmount'), 2),
            ],
        ];
    }
}
