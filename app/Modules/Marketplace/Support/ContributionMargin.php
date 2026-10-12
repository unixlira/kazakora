<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
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
 *   − frete pago pela loja (pré-postagem dos Correios, Flex)
 *   − ADS
 *
 * Antes cada tela fazia a sua conta: o painel inicial subtraía o frete que
 * o COMPRADOR paga, o KoraSync só tirava a taxa, e o Financeiro deixava a
 * Amazon zerada. As peças ficam aqui pra todas usarem a mesma definição.
 */
class ContributionMargin
{
    public const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    /**
     * Receita de um pedido: o valor dos produtos (subtotal). O frete fica
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

        return "(CASE WHEN {$orders}.origin = 'tiktok_shop' THEN {$tiktok}"
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
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->when($desde, fn ($q) => $q->where('orders.created_at', '>=', $desde))
            ->when($ate, fn ($q) => $q->where('orders.created_at', '<', $ate))
            ->tap(fn ($q) => self::semDevolucao($q));

        if (! $porCanal) {
            return round((float) $query->sum('correios_pre_postagens.postage_price'), 2);
        }

        return $query
            ->selectRaw('orders.origin as channel, COALESCE(SUM(correios_pre_postagens.postage_price), 0) as total')
            ->groupBy('orders.origin')
            ->pluck('total', 'channel')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * A conta inteira pra um intervalo [desde, ate) — usada no painel
     * inicial (hoje) e no KoraSync.
     *
     * @return array{receita: float, custo_produtos: float, taxas: float, frete: float, ads: float, bonificacoes: float, margem: float}
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
            ->sum('order_channel_fees.fee_amount'), 2);

        $flex = app(FlexDeliveryService::class)->summaryForPeriod($desde, $ate->copy()->subSecond())['total'];
        $frete = round(self::correios($desde, $ate) + $flex, 2);

        $bonificacoes = self::bonificacoes($desde, $ate);

        $ads = round((float) ChannelAdSpend::query()
            ->where('date', '>=', $desde->toDateString())
            ->where('date', '<', $ate->toDateString())
            ->sum('spend'), 2);

        return [
            'receita' => $receita,
            'custo_produtos' => $custo,
            'taxas' => $taxas,
            'frete' => $frete,
            'ads' => $ads,
            'bonificacoes' => $bonificacoes,
            'margem' => round($receita - $custo - $taxas - $frete - $ads + $bonificacoes, 2),
        ];
    }

    /**
     * A mesma conta de periodo(), separada por canal (auditoria 2026-10-11:
     * o Financeiro fazia uma conta própria por canal e não batia com o
     * painel nem com o e-mail). Flex e bônus do Flex ficam no Mercado Livre.
     *
     * @return array<string, array{pedidos: int, receita: float, custo_produtos: float, taxas: float, frete: float, ads: float, bonificacoes: float, margem: float}>
     */
    public function porCanal(Carbon $desde, Carbon $ate): array
    {
        $vendas = fn () => DB::table('orders')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->tap(fn ($q) => self::semDevolucao($q));

        $receita = $vendas()->selectRaw('orders.origin as canal, COUNT(*) as pedidos, COALESCE(SUM('.self::receitaSql().'), 0) as receita')
            ->groupBy('orders.origin')->get()->keyBy('canal');
        $custo = $vendas()->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('orders.origin as canal, COALESCE(SUM('.self::custoSql().'), 0) as total')
            ->groupBy('orders.origin')->pluck('total', 'canal');
        $taxas = $vendas()->join('order_channel_fees', 'order_channel_fees.order_id', '=', 'orders.id')
            ->selectRaw('orders.origin as canal, COALESCE(SUM(order_channel_fees.fee_amount), 0) as total')
            ->groupBy('orders.origin')->pluck('total', 'canal');
        $correios = self::correios($desde, $ate, porCanal: true);
        $ads = ChannelAdSpend::query()
            ->where('date', '>=', $desde->toDateString())
            ->where('date', '<', $ate->toDateString())
            ->selectRaw('channel, COALESCE(SUM(spend), 0) as total')
            ->groupBy('channel')->pluck('total', 'channel');
        $flex = app(FlexDeliveryService::class)->summaryForPeriod($desde, $ate->copy()->subSecond())['total'];
        $bonus = self::bonificacoes($desde, $ate);

        $canais = collect([$receita->keys(), $custo->keys(), $taxas->keys(), array_keys($correios), $ads->keys()])
            ->flatten()->filter()->unique()->values();
        if ($flex > 0 || $bonus > 0) {
            $canais->push(Order::ORIGIN_MERCADO_LIVRE);
        }

        return $canais->unique()->mapWithKeys(function ($canal) use ($receita, $custo, $taxas, $correios, $ads, $flex, $bonus) {
            $ml = $canal === Order::ORIGIN_MERCADO_LIVRE;
            $linha = [
                'pedidos' => (int) ($receita->get($canal)?->pedidos ?? 0),
                'receita' => round((float) ($receita->get($canal)?->receita ?? 0), 2),
                'custo_produtos' => round((float) ($custo[$canal] ?? 0), 2),
                'taxas' => round((float) ($taxas[$canal] ?? 0), 2),
                'frete' => round((float) ($correios[$canal] ?? 0) + ($ml ? $flex : 0), 2),
                'ads' => round((float) ($ads[$canal] ?? 0), 2),
                'bonificacoes' => $ml ? $bonus : 0.0,
            ];
            $linha['margem'] = round($linha['receita'] - $linha['custo_produtos'] - $linha['taxas'] - $linha['frete'] - $linha['ads'] + $linha['bonificacoes'], 2);

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
     *   soma(linhas.margem) − ads − flex + bonificacoes = porCanal()[canal].margem
     * (o teste OportunidadesCurvaAbcTest confere essa igualdade).
     *
     * @return array{linhas: list<array{order_id: int, product_id: ?int, product_name: string, quantidade: int, receita: float, custo_produtos: float, taxas: float, frete: float, margem: float}>, canal: array{ads: float, flex: float, bonificacoes: float}}
     */
    public function porItem(Carbon $desde, Carbon $ate, ?string $canal = null): array
    {
        $taxas = DB::table('order_channel_fees')->selectRaw('order_id, SUM(fee_amount) as total')->groupBy('order_id');
        $correios = DB::table('correios_pre_postagens')
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->selectRaw('order_id, SUM(postage_price) as total')->groupBy('order_id');

        $linhas = DB::table('orders')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoinSub($taxas, 'taxa_pedido', 'taxa_pedido.order_id', '=', 'orders.id')
            ->leftJoinSub($correios, 'correios_pedido', 'correios_pedido.order_id', '=', 'orders.id')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->when($canal, fn ($q) => $q->where('orders.origin', $canal))
            ->tap(fn ($q) => self::semDevolucao($q))
            ->selectRaw('orders.id as order_id, order_items.id as item_id, order_items.product_id, order_items.product_name,
                COALESCE(order_items.quantity, 0) as quantidade, COALESCE(order_items.subtotal, 0) as subtotal,
                '.self::receitaSql().' as receita_pedido,
                CASE WHEN order_items.id IS NULL THEN 0 ELSE '.self::custoSql().' END as custo,
                COALESCE(taxa_pedido.total, 0) as taxa_pedido, COALESCE(correios_pedido.total, 0) as frete_pedido')
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

        return [
            'linhas' => $linhas,
            'canal' => [
                'ads' => round((float) ChannelAdSpend::query()
                    ->where('date', '>=', $desde->toDateString())
                    ->where('date', '<', $ate->toDateString())
                    ->when($canal, fn ($q) => $q->where('channel', $canal))
                    ->sum('spend'), 2),
                'flex' => $ml ? (float) app(FlexDeliveryService::class)->summaryForPeriod($desde, $ate->copy()->subSecond())['total'] : 0.0,
                'bonificacoes' => $ml ? self::bonificacoes($desde, $ate) : 0.0,
            ],
        ];
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
