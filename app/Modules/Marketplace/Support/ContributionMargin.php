<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Admin\Support\Publicidade\PlataformaDeAnuncio;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Margem de contribuição — o que sobra no bolso de cada venda depois de
 * TODO custo variável dela (pedido explícito 2026-09-25: "o que vem pro
 * meu bolso limpinho"):
 *
 *   receita das vendas
 *   − custo dos produtos
 *   − taxa da plataforma (comissão)
 *   − frete pago pela loja (pré-postagem dos Correios menos o TURBO que o
 *     cliente do site pagou, Flex)
 *   − ADS (Shopee/ML Ads e o GMV Max do TikTok, cobrado no extrato)
 *   = margem
 *   − imposto (Simples Nacional sobre o valor da nota)
 *   = lucro (lucro líquido: é o número que o painel e o Financeiro mostram)
 *
 * Antes cada tela fazia a sua conta: o painel inicial subtraía o frete que
 * o COMPRADOR paga, o KoraSync só tirava a taxa, e o Financeiro deixava a
 * Amazon zerada. As peças ficam aqui pra todas usarem a mesma definição.
 */
class ContributionMargin
{
    public const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    /**
     * Receita de um pedido: o valor dos produtos (subtotal; no site, menos
     * os descontos da loja). O frete fica
     * de fora — na Shopee/ML/TikTok ele é pago pelo comprador direto à
     * logística do canal. EXCEÇÃO Amazon: pelo Bling ela sai pelos
     * Correios da própria loja (envio do vendedor), e o frete que o
     * comprador paga entra no repasse ao vendedor — então é receita, e o
     * custo dos Correios sai na linha de frete (postage_price).
     */
    public static function receitaSql(string $orders = 'orders'): string
    {
        // TikTok (2026-10-11): o subtotal é o preço CHEIO, antes do desconto
        // que a própria loja financia (ex.: R$ 239,90 com R$ 150 de desconto
        // do vendedor). Receita real = "Vendas líquidas dos produtos" do
        // demonstrativo; enquanto ele não chega, o valor que o cliente pagou.
        $tiktok = "COALESCE((SELECT SUM(msd.product_net_sales) FROM marketplace_settlement_details msd"
            ." WHERE msd.channel = 'tiktok_shop' AND msd.transaction_type = 'Pedido' AND msd.external_order_id = {$orders}.external_order_id),"
            ." CASE WHEN {$orders}.total - COALESCE({$orders}.shipping_cost, 0) > 0 THEN {$orders}.total - COALESCE({$orders}.shipping_cost, 0) ELSE 0 END)";

        // Site (2026-10-12): os descontos são da própria loja (cupom, Pix e o
        // desconto da plataforma do frete embutido, todos em discount_amount),
        // então a receita é o que sobra dos produtos depois deles.
        $loja = "CASE WHEN {$orders}.subtotal - COALESCE({$orders}.discount_amount, 0) > 0"
            ." THEN {$orders}.subtotal - COALESCE({$orders}.discount_amount, 0) ELSE 0 END";

        return "(CASE WHEN {$orders}.origin = 'tiktok_shop' THEN {$tiktok}"
            ." WHEN {$orders}.origin = 'loja' THEN {$loja}"
            ." ELSE {$orders}.subtotal + CASE WHEN {$orders}.origin = 'amazon' THEN COALESCE({$orders}.shipping_cost, 0) ELSE 0 END END)";
    }

    /**
     * Custo dos produtos de uma linha. Produto vinculado: custo unitário
     * do cadastro × quantidade. Item sem produto: manual_cost_price, que
     * já é o TOTAL da linha (ver migration add_manual_cost_price e
     * CashFlowController::updateItemCost) — antes as somas multiplicavam
     * esse total pela quantidade de novo.
     */
    public static function custoSql(string $items = 'order_items', string $products = 'products'): string
    {
        return "COALESCE({$items}.quantity * {$products}.cost_price, {$items}.manual_cost_price, 0)";
    }

    /**
     * Frete que a LOJA pagou nos Correios: o preço cotado da pré-postagem
     * gerada pra cada pedido (CorreiosAutoShipping / menu Correios),
     * atribuído ao mês da venda. Agrupado por canal quando pedido.
     *
     * @return float|array<string, float>
     */
    public static function correios(?Carbon $desde, ?Carbon $ate = null, bool $porCanal = false): float|array
    {
        $query = CorreiosPrePostagem::query()
            ->join('orders', 'orders.id', '=', 'correios_pre_postagens.order_id')
            ->where('correios_pre_postagens.status', CorreiosPrePostagem::STATUS_GERADA)
            // Devolução (cliente → loja) não é frete de envio da venda.
            ->where('correios_pre_postagens.devolucao', false)
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->when($desde, fn ($q) => $q->where('orders.created_at', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('orders.created_at', '<', $ate))
            ->tap(fn ($q) => self::semDevolucao($q));

        $turbo = self::turboCobrado($desde, $ate);

        if (! $porCanal) {
            return round((float) $query->sum('correios_pre_postagens.postage_price') - array_sum($turbo), 2);
        }

        return $query
            ->selectRaw(Order::canalSql().' as channel, COALESCE(SUM(correios_pre_postagens.postage_price), 0) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->map(fn ($total, $canal) => round((float) $total - ($turbo[$canal] ?? 0), 2))
            ->all();
    }

    /**
     * TURBO do site (2026-10-12): o cliente paga só a diferença SEDEX − PAC e
     * a loja paga o SEDEX inteiro na pré-postagem. O que o cliente pagou NÃO
     * é receita: abate o frete do pedido. Só de pedido com pré-postagem
     * gerada, para o frete não ficar negativo antes da etiqueta.
     *
     * @return array<string, float> por canal
     */
    public static function turboCobrado(?Carbon $desde, ?Carbon $ate = null): array
    {
        return DB::table('orders')
            ->where('orders.shipping_carrier_name', FreteEmbutido::TURBO)
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->when($desde, fn ($q) => $q->where('orders.created_at', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('orders.created_at', '<', $ate))
            ->whereExists(fn ($q) => $q->from('correios_pre_postagens')
                ->whereColumn('correios_pre_postagens.order_id', 'orders.id')
                ->where('correios_pre_postagens.status', CorreiosPrePostagem::STATUS_GERADA)
                ->where('correios_pre_postagens.devolucao', false))
            ->tap(fn ($q) => self::semDevolucao($q))
            ->selectRaw(Order::canalSql().' as canal, COALESCE(SUM(orders.shipping_cost), 0) as total')
            ->groupBy('canal')
            ->pluck('total', 'canal')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * A conta inteira pra um intervalo [desde, ate) — usada no painel
     * inicial (hoje) e no KoraSync.
     *
     * @return array{receita: float, custo_produtos: float, taxas: float, frete: float, ads: float, bonificacoes: float, margem: float, imposto: float, lucro: float}
     */
    public function periodo(Carbon $desde, Carbon $ate): array
    {
        $receita = round((float) Order::query()->vendaEfetiva()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $desde)
            ->where('created_at', '<', $ate)
            ->sum(DB::raw(self::receitaSql())), 2);

        $custo = round((float) DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->tap(fn ($q) => self::semDevolucao($q))
            ->selectRaw('COALESCE(SUM('.self::custoSql().'), 0) as total')
            ->value('total'), 2);

        $taxas = round((float) OrderChannelFee::query()
            ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->tap(fn ($q) => self::semDevolucao($q))
            ->sum('order_channel_fees.fee_amount') + array_sum(self::cobrancasSemPedido($desde, $ate)), 2);

        $flex = app(FlexDeliveryService::class)->summaryForPeriod($desde, $ate->copy()->subSecond())['total'];
        $frete = round(self::correios($desde, $ate) + $flex, 2);

        $bonificacoes = self::bonificacoes($desde, $ate);

        $ads = round((float) ChannelAdSpend::query()
            ->where('date', '>=', $desde->toDateString())
            ->where('date', '<', $ate->toDateString())
            ->sum('spend') + array_sum(self::adsDoExtrato($desde, $ate)), 2);

        $margem = round($receita - $custo - $taxas - $frete - $ads + $bonificacoes, 2);
        $imposto = round(array_sum(self::imposto($desde, $ate)), 2);

        return [
            'receita' => $receita,
            'custo_produtos' => $custo,
            'taxas' => $taxas,
            'frete' => $frete,
            'ads' => $ads,
            'bonificacoes' => $bonificacoes,
            'margem' => $margem,
            'imposto' => $imposto,
            'lucro' => round($margem - $imposto, 2),
        ];
    }

    /**
     * A mesma conta de periodo(), separada por canal (auditoria 2026-10-11:
     * o Financeiro fazia uma conta própria por canal e não batia com o
     * painel nem com o e-mail). Flex e bônus do Flex ficam no Mercado Livre.
     *
     * @return array<string, array{pedidos: int, receita: float, custo_produtos: float, taxas: float, frete: float, ads: float, bonificacoes: float, margem: float, imposto: float, lucro: float}>
     */
    public function porCanal(Carbon $desde, Carbon $ate): array
    {
        $vendas = fn () => DB::table('orders')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->tap(fn ($q) => self::semDevolucao($q));

        $receita = $vendas()->selectRaw(Order::canalSql().' as canal, COUNT(*) as pedidos, COALESCE(SUM('.self::receitaSql().'), 0) as receita')
            ->groupBy('canal')->get()->keyBy('canal');
        $custo = $vendas()->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw(Order::canalSql().' as canal, COALESCE(SUM('.self::custoSql().'), 0) as total')
            ->groupBy('canal')->pluck('total', 'canal');
        $taxas = $vendas()->join('order_channel_fees', 'order_channel_fees.order_id', '=', 'orders.id')
            ->selectRaw(Order::canalSql().' as canal, COALESCE(SUM(order_channel_fees.fee_amount), 0) as total')
            ->groupBy('canal')->pluck('total', 'canal');
        $cobrancas = self::cobrancasSemPedido($desde, $ate);
        $correios = self::correios($desde, $ate, porCanal: true);
        $ads = ChannelAdSpend::query()
            ->where('date', '>=', $desde->toDateString())
            ->where('date', '<', $ate->toDateString())
            ->selectRaw('channel, COALESCE(SUM(spend), 0) as total')
            ->groupBy('channel')->pluck('total', 'channel');
        // Meta Ads (2026-10-31): anúncio do site, não é origem de pedido.
        if (isset($ads[PlataformaDeAnuncio::META])) {
            $ads[Order::ORIGIN_STORE] = (float) ($ads[Order::ORIGIN_STORE] ?? 0) + (float) $ads[PlataformaDeAnuncio::META];
            unset($ads[PlataformaDeAnuncio::META]);
        }
        foreach (self::adsDoExtrato($desde, $ate) as $canal => $valor) {
            $ads[$canal] = (float) ($ads[$canal] ?? 0) + $valor;
        }
        $imposto = self::imposto($desde, $ate);
        $flex = app(FlexDeliveryService::class)->summaryForPeriod($desde, $ate->copy()->subSecond())['total'];
        $bonus = self::bonificacoes($desde, $ate);

        $canais = collect([$receita->keys(), $custo->keys(), $taxas->keys(), array_keys($correios), $ads->keys(), array_keys($cobrancas)])
            ->flatten()->filter()->unique()->values();
        if ($flex > 0 || $bonus > 0) {
            $canais->push(Order::ORIGIN_MERCADO_LIVRE);
        }

        return $canais->unique()->mapWithKeys(function ($canal) use ($receita, $custo, $taxas, $cobrancas, $correios, $ads, $imposto, $flex, $bonus) {
            $ml = $canal === Order::ORIGIN_MERCADO_LIVRE;
            $linha = [
                'pedidos' => (int) ($receita->get($canal)?->pedidos ?? 0),
                'receita' => round((float) ($receita->get($canal)?->receita ?? 0), 2),
                'custo_produtos' => round((float) ($custo[$canal] ?? 0), 2),
                'taxas' => round((float) ($taxas[$canal] ?? 0) + ($cobrancas[$canal] ?? 0), 2),
                'frete' => round((float) ($correios[$canal] ?? 0) + ($ml ? $flex : 0), 2),
                'ads' => round((float) ($ads[$canal] ?? 0), 2),
                'bonificacoes' => $ml ? $bonus : 0.0,
            ];
            $linha['margem'] = round($linha['receita'] - $linha['custo_produtos'] - $linha['taxas'] - $linha['frete'] - $linha['ads'] + $linha['bonificacoes'], 2);
            $linha['imposto'] = round((float) ($imposto[$canal] ?? 0), 2);
            $linha['lucro'] = round($linha['margem'] - $linha['imposto'], 2);

            return [$canal => $linha];
        })->all();
    }

    /**
     * A mesma conta de porCanal(), aberta por linha de pedido (Oportunidades,
     * 2026-10-12: curva ABC com lucro por produto). Nada de conta nova: a
     * receita do pedido (receitaSql), a taxa (order_channel_fees) e o frete
     * dos Correios do pedido são repartidos entre os itens pelo peso do item
     * no pedido (subtotal; sem subtotal, quantidade). Custo é custoSql() do
     * próprio item.
     *
     * ADS, Flex e bônus do Flex são do canal inteiro, não de um pedido: ficam
     * em 'canal' e NÃO entram na margem de cada linha. Por isso
     *   soma(linhas.margem) − ads − flex + bonificacoes − cobrancas = porCanal()[canal].margem
     * (o teste OportunidadesCurvaAbcTest confere essa igualdade).
     *
     * @return array{linhas: list<array{order_id: int, product_id: ?int, product_name: string, quantidade: int, receita: float, custo_produtos: float, taxas: float, frete: float, margem: float}>, canal: array{ads: float, flex: float, bonificacoes: float, cobrancas: float}}
     */
    public function porItem(Carbon $desde, Carbon $ate, ?string $canal = null): array
    {
        $taxas = DB::table('order_channel_fees')
            ->selectRaw('order_id, SUM(fee_amount) as total, MAX(CASE WHEN source = ? THEN 1 ELSE 0 END) as estimada', [OrderChannelFee::SOURCE_ESTIMATE])
            ->groupBy('order_id');
        $correios = DB::table('correios_pre_postagens')
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->where('devolucao', false)
            ->selectRaw('order_id, SUM(postage_price) as total')->groupBy('order_id');

        $linhas = DB::table('orders')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoinSub($taxas, 'taxa_pedido', 'taxa_pedido.order_id', '=', 'orders.id')
            ->leftJoinSub($correios, 'correios_pedido', 'correios_pedido.order_id', '=', 'orders.id')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->when($canal, fn ($q) => Order::filtrarCanal($q, $canal))
            ->tap(fn ($q) => self::semDevolucao($q))
            ->selectRaw('orders.id as order_id, orders.created_at as pedido_em, '.Order::canalSql().' as canal_pedido,
                order_items.id as item_id, order_items.product_id, order_items.product_name,
                CASE WHEN products.cost_price IS NOT NULL OR order_items.manual_cost_price IS NOT NULL THEN 1 ELSE 0 END as tem_custo,
                CASE WHEN taxa_pedido.order_id IS NULL THEN 0 ELSE 1 END as tem_taxa,
                COALESCE(taxa_pedido.estimada, 0) as taxa_estimada,
                COALESCE(order_items.quantity, 0) as quantidade, COALESCE(order_items.subtotal, 0) as subtotal,
                '.self::receitaSql().' as receita_pedido,
                CASE WHEN order_items.id IS NULL THEN 0 ELSE '.self::custoSql().' END as custo,
                COALESCE(taxa_pedido.total, 0) as taxa_pedido,
                COALESCE(correios_pedido.total, 0) - CASE WHEN correios_pedido.total IS NOT NULL AND orders.shipping_carrier_name = ?
                    THEN COALESCE(orders.shipping_cost, 0) ELSE 0 END as frete_pedido', [FreteEmbutido::TURBO])
            ->orderBy('orders.id')->orderBy('order_items.id')
            ->get()
            ->groupBy('order_id')
            ->flatMap(function ($itens) {
                $somaSubtotal = (float) $itens->sum('subtotal');
                $somaQuantidade = (float) $itens->sum('quantidade');

                return $itens->map(function ($item) use ($itens, $somaSubtotal, $somaQuantidade) {
                    $peso = match (true) {
                        $somaSubtotal > 0 => (float) $item->subtotal / $somaSubtotal,
                        $somaQuantidade > 0 => (float) $item->quantidade / $somaQuantidade,
                        default => 1 / $itens->count(),
                    };
                    $receita = (float) $item->receita_pedido * $peso;
                    $taxas = (float) $item->taxa_pedido * $peso;
                    $frete = (float) $item->frete_pedido * $peso;
                    $custo = (float) $item->custo;

                    return [
                        'order_id' => (int) $item->order_id,
                        // Campos de exibição da aba Vendas do Fluxo de Caixa
                        // (2026-10-19); não entram em nenhuma soma.
                        'item_id' => $item->item_id !== null ? (int) $item->item_id : null,
                        'pedido_em' => (string) $item->pedido_em,
                        'canal' => (string) $item->canal_pedido,
                        'tem_custo' => (bool) $item->tem_custo,
                        'tem_taxa' => (bool) $item->tem_taxa,
                        'taxa_estimada' => (bool) $item->taxa_estimada,
                        'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                        'product_name' => (string) ($item->product_name ?? ''),
                        'quantidade' => (int) $item->quantidade,
                        'receita' => $receita,
                        'custo_produtos' => $custo,
                        'taxas' => $taxas,
                        'frete' => $frete,
                        'margem' => $receita - $custo - $taxas - $frete,
                    ];
                });
            })
            ->values()
            ->all();

        $ml = $canal === null || $canal === Order::ORIGIN_MERCADO_LIVRE;
        $cobrancas = self::cobrancasSemPedido($desde, $ate);

        return [
            'linhas' => $linhas,
            'canal' => [
                'ads' => round((float) ChannelAdSpend::query()
                    ->where('date', '>=', $desde->toDateString())
                    ->where('date', '<', $ate->toDateString())
                    ->when($canal, fn ($q) => $q->whereIn('channel', $canal === Order::ORIGIN_STORE ? [$canal, PlataformaDeAnuncio::META] : [$canal]))
                    ->sum('spend') + ($canal === null ? array_sum(self::adsDoExtrato($desde, $ate)) : (self::adsDoExtrato($desde, $ate)[$canal] ?? 0)), 2),
                'flex' => $ml ? (float) app(FlexDeliveryService::class)->summaryForPeriod($desde, $ate->copy()->subSecond())['total'] : 0.0,
                'bonificacoes' => $ml ? self::bonificacoes($desde, $ate) : 0.0,
                'cobrancas' => round($canal === null ? array_sum($cobrancas) : ($cobrancas[$canal] ?? 0), 2),
            ],
        ];
    }

    /**
     * Anúncio cobrado dentro do extrato do canal, por canal (auditoria
     * 2026-10-18): o GMV Max do TikTok vem como linha própria "Pagamento de
     * GMV para anúncios do TikTok", sem pedido, e ficava fora do ADS (R$ 209
     * em out/2026). Pela data do demonstrativo, que é o dia da cobrança. A
     * taxa real do pedido (vendas líquidas − liquidado) não inclui essa
     * linha, então não há dupla contagem. Uma linha por demonstrativo: o
     * mesmo lançamento já chegou com o SKU vazio, nulo ou "/".
     *
     * @return array<string, float>
     */
    public static function adsDoExtrato(Carbon $desde, Carbon $ate): array
    {
        if (! Schema::hasTable('marketplace_settlement_details')) {
            return [];
        }

        $linhas = DB::table('marketplace_settlement_details')
            ->where('transaction_type', 'like', 'Pagamento de GMV%')
            ->where('settlement_date', '>=', $desde->toDateString())
            ->where('settlement_date', '<', $ate->toDateString())
            ->selectRaw('channel, statement_id, external_order_id, MIN(payout_amount) as valor')
            ->groupBy('channel', 'statement_id', 'external_order_id');

        return DB::query()->fromSub($linhas, 'gmv')
            ->selectRaw('channel, COALESCE(SUM(-valor), 0) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->map(fn ($total) => round(max(0.0, (float) $total), 2))
            ->all();
    }

    /**
     * Dinheiro do canal que não pertence a uma venda efetiva (auditoria
     * 2026-10-31), por canal; positivo é custo, negativo é crédito. Entra na
     * linha de taxas:
     *  - financeiro_lancamentos: Full e Minha página do ML, DIFAL da Shopee;
     *  - extrato do TikTok: reembolso de logística/plataforma (crédito) e
     *    cobrança em pedido que não conta como venda;
     *  - frete do comprador recebido no Mercado Pago (marketplace_shipment);
     *  - etiqueta dos Correios gerada para pedido que não conta como venda.
     *
     * @return array<string, float>
     */
    public static function cobrancasSemPedido(Carbon $desde, Carbon $ate): array
    {
        $total = [];
        $somar = function (string $canal, float $valor) use (&$total) {
            $total[$canal] = ($total[$canal] ?? 0.0) + $valor;
        };
        $efetiva = fn ($q) => $q->whereIn('orders.status', self::REVENUE_STATUSES)->tap(fn ($v) => self::semDevolucao($v));

        if (Schema::hasTable('financeiro_lancamentos')) {
            DB::table('financeiro_lancamentos')
                ->where('data', '>=', $desde->toDateString())
                ->where('data', '<', $ate->toDateString())
                ->selectRaw('canal, SUM(valor) as total')->groupBy('canal')->get()
                ->each(fn ($l) => $somar($l->canal, (float) $l->total));
        }

        if (Schema::hasTable('marketplace_settlement_details')) {
            $reembolso = (float) DB::table('marketplace_settlement_details')
                ->where('channel', Order::ORIGIN_TIKTOK_SHOP)
                ->where('transaction_type', 'like', 'Reembolso%')
                ->where('settlement_date', '>=', $desde->toDateString())
                ->where('settlement_date', '<', $ate->toDateString())
                ->sum('payout_amount');
            $somar(Order::ORIGIN_TIKTOK_SHOP, -$reembolso);

            $foraDaVenda = (float) DB::table('marketplace_settlement_details as d')
                ->join('orders', fn ($j) => $j->on('orders.external_order_id', '=', 'd.external_order_id')->where('orders.origin', Order::ORIGIN_TIKTOK_SHOP))
                ->where('d.channel', Order::ORIGIN_TIKTOK_SHOP)
                ->where('d.transaction_type', 'Pedido')
                ->where('d.payout_amount', '<', 0)
                ->where('orders.created_at', '>=', $desde)
                ->where('orders.created_at', '<', $ate)
                ->whereNotIn('orders.id', fn ($q) => $efetiva($q->from('orders')->select('orders.id')))
                ->sum('d.payout_amount');
            $somar(Order::ORIGIN_TIKTOK_SHOP, -$foraDaVenda);
        }

        if (Schema::hasTable('mercado_pago_pagamentos')) {
            $somar(Order::ORIGIN_MERCADO_LIVRE, -(float) DB::table('mercado_pago_pagamentos')
                ->whereNull('order_id')
                ->where('descricao', 'marketplace_shipment')
                ->where('status', 'approved')
                ->where('data_criacao', '>=', $desde)
                ->where('data_criacao', '<', $ate)
                ->sum('valor'));
        }

        DB::table('correios_pre_postagens')
            ->join('orders', 'orders.id', '=', 'correios_pre_postagens.order_id')
            ->where('correios_pre_postagens.status', CorreiosPrePostagem::STATUS_GERADA)
            ->where('correios_pre_postagens.devolucao', false)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->whereNotIn('orders.id', fn ($q) => $efetiva($q->from('orders')->select('orders.id')))
            ->selectRaw(Order::canalSql().' as canal, COALESCE(SUM(correios_pre_postagens.postage_price), 0) as total')
            ->groupBy('canal')->get()
            ->each(fn ($l) => $somar((string) $l->canal, (float) $l->total));

        return array_filter(array_map(fn (float $v) => round($v, 2), $total), fn (float $v) => $v != 0.0);
    }

    /** Alíquota efetiva do Simples (%), informada pelo contador. */
    public static function aliquotaSimples(): float
    {
        return (float) config('loja.simples.aliquota', 4.5);
    }

    /**
     * Imposto do Simples por canal (auditoria 2026-10-11, entrou na conta de
     * todas as telas em 2026-10-18): alíquota × valor da NOTA de venda
     * autorizada (o que o cliente pagou, frete incluso quando vai na nota);
     * pedido sem nota importada ainda usa a receita. Só pedidos a partir de
     * LOJA_SIMPLES_DESDE (antes a empresa era MEI). Estimativa do DAS: o
     * oficial é a guia, que vence dia 20 do mês seguinte.
     *
     * @return array<string, float>
     */
    public static function imposto(Carbon $desde, Carbon $ate): array
    {
        $inicio = Carbon::parse((string) config('loja.simples.desde', '2026-09-01'))->startOfDay();
        $desde = $desde->copy()->max($inicio);
        if ($desde->gte($ate) || self::aliquotaSimples() <= 0) {
            return [];
        }

        return array_map(
            fn (float $base) => round($base * self::aliquotaSimples() / 100, 2),
            self::basePorCanal($desde, $ate),
        );
    }

    /**
     * Faturamento em nota por canal (Fluxo de Caixa, 2026-10-19): a base do
     * imposto, ou seja, o valor da NF-e de venda autorizada de cada pedido
     * (sem nota importada ainda, a receita). imposto() = base × alíquota,
     * só a partir de LOJA_SIMPLES_DESDE; aqui a base vale para qualquer data.
     *
     * @return array<string, float>
     */
    public static function baseImposto(Carbon $desde, Carbon $ate): array
    {
        return array_map(fn (float $base) => round($base, 2), self::basePorCanal($desde, $ate));
    }

    /** @return array<string, float> */
    private static function basePorCanal(Carbon $desde, Carbon $ate): array
    {
        $nota = 'SELECT i.valor_total FROM invoices i WHERE i.order_id = orders.id AND i.status = \'authorized\''
            ." AND i.operation_type = 'saida' AND i.cancelada_em IS NULL LIMIT 1";

        return DB::table('orders')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->tap(fn ($q) => self::semDevolucao($q))
            ->selectRaw(Order::canalSql().' as canal, COALESCE(SUM(COALESCE(('.$nota.'), '.self::receitaSql().')), 0) as base')
            ->groupBy('canal')
            ->pluck('base', 'canal')
            ->map(fn ($base) => (float) $base)
            ->all();
    }

    /**
     * Vendas brutas (Fluxo de Caixa, 2026-10-19): o que os clientes pagaram,
     * com frete (orders.total), das mesmas vendas efetivas da receita.
     */
    public static function vendasBrutas(Carbon $desde, Carbon $ate, ?string $canal = null): float
    {
        return round((float) DB::table('orders')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->when($canal, fn ($q) => Order::filtrarCanal($q, $canal))
            ->tap(fn ($q) => self::semDevolucao($q))
            ->sum('orders.total'), 2);
    }

    /**
     * Linha zerada da conta, para canal ou período sem venda.
     *
     * @return array<string, float|int>
     */
    public static function contaVazia(): array
    {
        return ['pedidos' => 0, 'receita' => 0.0, 'custo_produtos' => 0.0, 'taxas' => 0.0, 'frete' => 0.0, 'ads' => 0.0,
            'bonificacoes' => 0.0, 'margem' => 0.0, 'imposto' => 0.0, 'lucro' => 0.0];
    }

    /**
     * A conta de um intervalo inteiro, ou de um canal só (porCanal()).
     *
     * @return array<string, float|int>
     */
    public function conta(Carbon $desde, Carbon $ate, ?string $canal = null): array
    {
        if ($canal === null) {
            return $this->periodo($desde, $ate);
        }

        return array_merge(self::contaVazia(), $this->porCanal($desde, $ate)[$canal] ?? []);
    }

    /**
     * Série do Fluxo de Caixa (2026-10-19): a MESMA conta de periodo() (ou de
     * porCanal() com canal) chamada balde a balde (dia, semana ISO ou mês,
     * cortados no intervalo). Nenhuma fórmula nova: a soma dos baldes é a
     * conta do intervalo inteiro. Balde que já fechou fica 10 min em cache;
     * o que inclui hoje é sempre recalculado.
     *
     * @return list<array<string, mixed>>
     */
    public function serie(Carbon $desde, Carbon $ate, string $agrupamento = 'dia', ?string $canal = null): array
    {
        $hoje = Carbon::today();
        $baldes = [];
        $cursor = $desde->copy()->startOfDay();
        while ($cursor->lt($ate)) {
            $fim = match ($agrupamento) {
                'mes' => $cursor->copy()->startOfMonth()->addMonthNoOverflow(),
                'semana' => $cursor->copy()->startOfWeek(Carbon::MONDAY)->addWeek(),
                default => $cursor->copy()->addDay(),
            };
            $fim = $fim->min($ate);
            $baldes[] = [$cursor->copy(), $fim->copy()];
            $cursor = $fim;
        }

        return array_map(function (array $balde) use ($canal, $hoje) {
            [$inicio, $fim] = $balde;
            $calcular = fn () => $this->conta($inicio, $fim, $canal);
            $conta = $fim->lte($hoje)
                ? \Illuminate\Support\Facades\Cache::remember('cm:serie:'.($canal ?? 'todos').':'.$inicio->toDateTimeString().':'.$fim->toDateTimeString(), now()->addMinutes(10), $calcular)
                : $calcular();

            return ['inicio' => $inicio->toDateString(), 'fim' => $fim->copy()->subDay()->toDateString()] + $conta;
        }, $baldes);
    }

    /**
     * Quanto da taxa do período já é real (API, relatório, extrato, manual)
     * e quanto ainda é estimada, por canal (Fluxo de Caixa, 2026-10-19).
     *
     * @return array<string, array{real: float, estimada: float, pedidos_real: int, pedidos_estimada: int}>
     */
    public static function qualidadeTaxas(Carbon $desde, Carbon $ate): array
    {
        return DB::table('orders')
            ->join('order_channel_fees', 'order_channel_fees.order_id', '=', 'orders.id')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->tap(fn ($q) => self::semDevolucao($q))
            ->selectRaw(Order::canalSql().' as canal,
                COALESCE(SUM(CASE WHEN order_channel_fees.source = ? THEN 0 ELSE order_channel_fees.fee_amount END), 0) as real_total,
                COALESCE(SUM(CASE WHEN order_channel_fees.source = ? THEN order_channel_fees.fee_amount ELSE 0 END), 0) as estimada_total,
                COUNT(DISTINCT CASE WHEN order_channel_fees.source = ? THEN NULL ELSE orders.id END) as pedidos_real,
                COUNT(DISTINCT CASE WHEN order_channel_fees.source = ? THEN orders.id ELSE NULL END) as pedidos_estimada',
                array_fill(0, 4, OrderChannelFee::SOURCE_ESTIMATE))
            ->groupBy('canal')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->canal => [
                'real' => round((float) $l->real_total, 2),
                'estimada' => round((float) $l->estimada_total, 2),
                'pedidos_real' => (int) $l->pedidos_real,
                'pedidos_estimada' => (int) $l->pedidos_estimada,
            ]])
            ->all();
    }

    /**
     * Bônus do Flex que o Mercado Livre deposita no Mercado Pago
     * ("bonificaciones_flex", ver ConciliacaoMercadoPago) — dinheiro que
     * entra e abate o custo do motoboy. Pela data em que caiu.
     */
    public static function bonificacoes(Carbon $desde, Carbon $ate): float
    {
        if (! Schema::hasTable('mercado_pago_pagamentos')) {
            return 0.0;
        }

        return round((float) DB::table('mercado_pago_pagamentos')
            ->where('canal', 'bonificacao')
            ->where('status', 'approved')
            ->where('data_criacao', '>=', $desde)
            ->where('data_criacao', '<', $ate)
            ->sum(DB::raw('liquido - reembolsado')), 2);
    }

    /** Só venda efetiva — mesmo filtro de Order::vendaEfetiva(). */
    public static function semDevolucao($query, string $orders = 'orders'): void
    {
        $query
            ->where(fn ($q) => $q->where("{$orders}.origin", '!=', Order::ORIGIN_MANUAL_INVOICE)->orWhereNull("{$orders}.origin"))
            ->whereNull("{$orders}.estornado_em")
            ->where(fn ($q) => $q->whereNotIn("{$orders}.origin", [Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_SALES_RETURN_INVOICE])->orWhereNull("{$orders}.origin"))
            ->where(fn ($q) => $q->whereNotIn("{$orders}.fiscal_operation_type", ['purchase_return', 'sales_return'])->orWhereNull("{$orders}.fiscal_operation_type"));
    }
}
