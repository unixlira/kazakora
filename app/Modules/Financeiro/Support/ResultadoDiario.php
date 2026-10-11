<?php

namespace App\Modules\Financeiro\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado do dia e do mês (pedido 2026-10-10). Mesma conta do e-mail de
 * margem que a Naia mandava, agora dentro do sistema e com os dados da
 * produção:
 *
 *   lucro líquido = receita − custo do produto − taxas do canal − anúncios
 *                   − frete pago − imposto (Simples Nacional)
 *
 * - Receita: subtotal − desconto dos pedidos pagos/enviados/entregues,
 *   sem notas de devolução.
 * - Taxa do canal: a real gravada no pedido; sem ela (ex.: TikTok antes do
 *   extrato), a média histórica do próprio extrato do canal, como provisão.
 * - Imposto: alíquota do Simples (config loja.simples) sobre a receita, a
 *   partir da data de início no Simples. É a estimativa do DAS do mês.
 */
class ResultadoDiario
{
    private const STATUS_VALIDOS = ['paid', 'shipped', 'completed'];

    private const ORIGENS_DEVOLUCAO = ['nota_devolucao_compra', 'nota_devolucao_venda'];

    private const OPERACOES_DEVOLUCAO = ['purchase_return', 'sales_return'];

    /**
     * @return array{data: Carbon, dia: array, mes: array, dias: list<array>, aliquota: float, vencimento_das: Carbon}
     */
    public function calcular(Carbon $data): array
    {
        $data = $data->copy()->startOfDay();
        $taxasHistoricas = $this->taxasHistoricasPorCanal();

        $dias = [];
        $acumulado = 0.0;
        for ($dia = $data->copy()->startOfMonth(); $dia->lte($data); $dia->addDay()) {
            $linha = $this->dia($dia, $taxasHistoricas);
            $acumulado = round($acumulado + $linha['lucro'], 2);
            $linha['acumulado'] = $acumulado;
            $dias[] = $linha;
        }

        $somar = fn (string $campo) => round(array_sum(array_column($dias, $campo)), 2);

        return [
            'data' => $data,
            'dia' => end($dias) ?: $this->dia($data, $taxasHistoricas),
            'mes' => [
                'receita' => $somar('receita'),
                'custo_produto' => $somar('custo_produto'),
                'taxas_canal' => $somar('taxas_canal'),
                'anuncios' => $somar('anuncios'),
                'frete' => $somar('frete'),
                'impostos' => $somar('impostos'),
                'lucro' => $somar('lucro'),
                'pedidos' => (int) array_sum(array_column($dias, 'pedidos')),
            ],
            'dias' => $dias,
            'aliquota' => $this->aliquota(),
            // DAS vence no dia 20 do mês seguinte (Simples Nacional).
            'vencimento_das' => $data->copy()->startOfMonth()->addMonth()->day(20),
        ];
    }

    public function aliquota(): float
    {
        return (float) config('loja.simples.aliquota', 4.5);
    }

    private function dia(Carbon $dia, array $taxasHistoricas): array
    {
        $data = $dia->toDateString();

        $porCanal = $this->pedidos(DB::table('orders'))
            ->whereDate('orders.created_at', $data)
            ->selectRaw("COALESCE(orders.origin, 'site') as canal,
                COUNT(*) as pedidos,
                COALESCE(SUM(orders.subtotal - COALESCE(orders.discount_amount, 0)), 0) as receita,
                COALESCE(SUM(orders.shipping_cost), 0) as frete")
            ->groupBy('orders.origin')
            ->get()
            ->keyBy('canal');

        $receita = round((float) $porCanal->sum('receita'), 2);

        $custoProduto = round((float) $this->pedidos(DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id'))
            ->whereDate('orders.created_at', $data)
            ->sum(DB::raw('order_items.quantity * COALESCE(order_items.manual_cost_price, products.cost_price, 0)')), 2);

        $taxasReais = $this->pedidos(DB::table('order_channel_fees')
            ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id'))
            ->whereDate('orders.created_at', $data)
            ->selectRaw("COALESCE(orders.origin, 'site') as canal, COALESCE(SUM(order_channel_fees.fee_amount), 0) as taxa")
            ->groupBy('orders.origin')
            ->get()
            ->keyBy('canal');

        $taxaEstimada = 0.0;
        $canaisEstimados = [];
        foreach ($porCanal as $canal => $linha) {
            $real = (float) ($taxasReais->get($canal)?->taxa ?? 0);
            $media = $taxasHistoricas[$canal] ?? null;
            if ((float) $linha->receita > 0 && $real <= 0 && $media !== null) {
                $taxaEstimada += round((float) $linha->receita * $media, 2);
                $canaisEstimados[] = $canal;
            }
        }
        $taxasCanal = round((float) $taxasReais->sum('taxa') + $taxaEstimada, 2);

        $anuncios = Schema::hasTable('channel_ad_spends')
            ? round((float) DB::table('channel_ad_spends')->whereDate('date', $data)->sum('spend'), 2)
            : 0.0;

        $frete = round((float) $porCanal->sum('frete'), 2);
        $inicioSimples = Carbon::parse((string) config('loja.simples.desde', '2026-09-01'))->startOfDay();
        $impostos = $dia->gte($inicioSimples) ? round($receita * $this->aliquota() / 100, 2) : 0.0;

        return [
            'data' => $dia->copy(),
            'pedidos' => (int) $porCanal->sum('pedidos'),
            'receita' => $receita,
            'custo_produto' => $custoProduto,
            'taxas_canal' => $taxasCanal,
            'taxas_estimadas' => round($taxaEstimada, 2),
            'canais_estimados' => $canaisEstimados,
            'anuncios' => $anuncios,
            'frete' => $frete,
            'impostos' => $impostos,
            'lucro' => round($receita - $custoProduto - $taxasCanal - $anuncios - $frete - $impostos, 2),
        ];
    }

    /** Pedidos que contam como venda: pagos/enviados/entregues, sem devolução. */
    private function pedidos($query)
    {
        return $query
            ->whereIn('orders.status', self::STATUS_VALIDOS)
            ->where(fn ($q) => $q->whereNotIn('orders.origin', self::ORIGENS_DEVOLUCAO)->orWhereNull('orders.origin'))
            ->where(fn ($q) => $q->whereNotIn('orders.fiscal_operation_type', self::OPERACOES_DEVOLUCAO)->orWhereNull('orders.fiscal_operation_type'));
    }

    /**
     * Quanto cada canal leva da venda, pela média do extrato real (comissão,
     * frete do canal, afiliados, ads do GMV e ajustes negativos).
     *
     * @return array<string, float>
     */
    private function taxasHistoricasPorCanal(): array
    {
        if (! Schema::hasTable('marketplace_settlement_details')) {
            return [];
        }

        return DB::table('marketplace_settlement_details')
            ->where('transaction_type', 'Pedido')
            ->selectRaw('channel,
                COALESCE(SUM(product_net_sales), 0) as receita,
                COALESCE(SUM(ABS(platform_fees_taxes)), 0)
                  + COALESCE(SUM(CASE WHEN net_shipping_cost < 0 THEN ABS(net_shipping_cost) ELSE 0 END), 0)
                  + COALESCE(SUM(ABS(affiliate_commissions)), 0)
                  + COALESCE(SUM(ABS(gmv_max_ad_fee)), 0)
                  + COALESCE(SUM(CASE WHEN adjustment_amount < 0 THEN ABS(adjustment_amount) ELSE 0 END), 0) as custos')
            ->groupBy('channel')
            ->get()
            ->filter(fn ($linha) => (float) $linha->receita > 0)
            ->mapWithKeys(fn ($linha) => [$linha->channel => round((float) $linha->custos / (float) $linha->receita, 6)])
            ->all();
    }
}
