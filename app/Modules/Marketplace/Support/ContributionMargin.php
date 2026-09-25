<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        return "({$orders}.subtotal + CASE WHEN {$orders}.origin = 'amazon' THEN COALESCE({$orders}.shipping_cost, 0) ELSE 0 END)";
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
     * @return array{receita: float, custo_produtos: float, taxas: float, frete: float, ads: float, margem: float}
     */
    public function periodo(Carbon $desde, Carbon $ate): array
    {
        $receita = round((float) Order::query()->nonPurchaseReturn()
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
            'margem' => round($receita - $custo - $taxas - $frete - $ads, 2),
        ];
    }

    /** Notas de devolução não são venda — mesmo filtro de Order::nonPurchaseReturn(). */
    public static function semDevolucao($query, string $orders = 'orders'): void
    {
        $query
            ->where(fn ($q) => $q->whereNotIn("{$orders}.origin", [Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_SALES_RETURN_INVOICE])->orWhereNull("{$orders}.origin"))
            ->where(fn ($q) => $q->whereNotIn("{$orders}.fiscal_operation_type", ['purchase_return', 'sales_return'])->orWhereNull("{$orders}.fiscal_operation_type"));
    }
}
