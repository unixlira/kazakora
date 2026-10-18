<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resultado do dia e do mês (pedido 2026-10-10), com a MESMA conta do painel,
 * do Financeiro e do KoraSync — ContributionMargin::periodo(), que desde
 * 2026-10-18 já traz o imposto do Simples (ContributionMargin::imposto()):
 *
 *   lucro líquido = receita − custo dos produtos − taxas dos canais
 *                   − frete pago pela loja (Correios/Flex) − anúncios − imposto
 *
 * O frete que o COMPRADOR paga não entra (na Amazon ele é receita, porque a
 * loja envia pelos próprios Correios). Anúncio = consumo diário (Shopee/ML
 * Ads); recarga de carteira nunca é gasto. Mapa completo dos custos e das
 * armadilhas: docs/financeiro-mapa-de-custos.md.
 */
class ResultadoDiario
{
    public function __construct(private readonly ContributionMargin $margem)
    {
    }

    /**
     * @return array{data: Carbon, dia: array, mes: array, dias: list<array>, aliquota: float, vencimento_das: Carbon}
     */
    public function calcular(Carbon $data): array
    {
        $data = $data->copy()->startOfDay();

        $dias = [];
        $acumulado = 0.0;
        for ($dia = $data->copy()->startOfMonth(); $dia->lte($data); $dia->addDay()) {
            $linha = $this->dia($dia);
            $acumulado = round($acumulado + $linha['lucro'], 2);
            $linha['acumulado'] = $acumulado;
            $dias[] = $linha;
        }

        $somar = fn (string $campo) => round(array_sum(array_column($dias, $campo)), 2);

        return [
            'data' => $data,
            'dia' => end($dias) ?: $this->dia($data),
            'mes' => [
                'receita' => $somar('receita'),
                'custo_produto' => $somar('custo_produto'),
                'taxas_canal' => $somar('taxas_canal'),
                'anuncios' => $somar('anuncios'),
                'frete' => $somar('frete'),
                'bonificacoes' => $somar('bonificacoes'),
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
        return ContributionMargin::aliquotaSimples();
    }

    private function dia(Carbon $dia): array
    {
        $inicio = $dia->copy()->startOfDay();
        $fim = $inicio->copy()->addDay();
        $conta = $this->margem->periodo($inicio, $fim);

        $pedidos = Order::query()->vendaEfetiva()
            ->whereIn('status', ContributionMargin::REVENUE_STATUSES)
            ->where('created_at', '>=', $inicio)->where('created_at', '<', $fim)
            ->count();

        // Taxa ainda estimada (ex.: TikTok antes do extrato): mostrada à parte.
        $estimadas = round((float) DB::table('order_channel_fees')
            ->join('orders', 'orders.id', '=', 'order_channel_fees.order_id')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $inicio)->where('orders.created_at', '<', $fim)
            ->where('order_channel_fees.source', 'estimate')
            ->sum('order_channel_fees.fee_amount'), 2);

        return [
            'data' => $dia->copy(),
            'pedidos' => $pedidos,
            'receita' => $conta['receita'],
            'custo_produto' => $conta['custo_produtos'],
            'taxas_canal' => $conta['taxas'],
            'taxas_estimadas' => $estimadas,
            'frete' => $conta['frete'],
            'anuncios' => $conta['ads'],
            'bonificacoes' => $conta['bonificacoes'],
            'impostos' => $conta['imposto'],
            'lucro' => $conta['lucro'],
        ];
    }
}
