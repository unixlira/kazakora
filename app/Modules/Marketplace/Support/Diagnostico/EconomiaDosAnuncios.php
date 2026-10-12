<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lucro de cada anúncio de um canal nos últimos N dias, com dado REAL do
 * KazaKora (pedido 2026-10-11, seções Cauê/Diego do dashboard):
 *
 *   lucro antes do ADS = receita − taxa real do canal − custo − imposto
 *   ROAS de empate     = preço ÷ lucro antes do ADS por unidade
 *                        (abaixo disso o ADS come todo o lucro)
 *   ROAS meta          = preço ÷ (lucro antes do ADS − 15% do preço)
 *                        (o mínimo para sobrar a margem-alvo de 15%)
 *
 * Mesma definição da ContributionMargin: receita = subtotal; taxa = a de
 * order_channel_fees (real quando há extrato/conciliação), rateada entre os
 * itens do pedido pelo subtotal.
 */
class EconomiaDosAnuncios
{
    public const MARGEM_ALVO = 0.15;

    /**
     * @return array<string, array{pedidos: int, unidades: int, receita: float, taxa: float, custo: float, imposto: float, sem_custo: bool, preco: float, lucro_unidade: float, margem: float, roas_empate: ?float, roas_meta: ?float}>
     */
    public function porAnuncio(string $canal, Carbon $desde): array
    {
        // Um anúncio por produto (se houver anúncio duplicado, conta só um).
        $mapa = DB::table('product_channel_listings')
            ->where('channel', $canal)
            ->whereNotNull('external_id')
            ->selectRaw('product_id, MIN(external_id) as external_id')
            ->groupBy('product_id');

        $linhas = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->joinSub($mapa, 'l', 'l.product_id', '=', 'oi.product_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('order_channel_fees as f', 'f.order_id', '=', 'o.id')
            ->where('o.origin', $canal)
            ->whereIn('o.status', ContributionMargin::REVENUE_STATUSES)
            ->where('o.created_at', '>=', $desde)
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q, 'o'))
            ->selectRaw('l.external_id,
                COUNT(DISTINCT o.id) as pedidos,
                SUM(oi.quantity) as unidades,
                SUM(oi.subtotal) as receita,
                SUM(COALESCE(f.fee_amount, 0) * oi.subtotal / NULLIF(o.subtotal, 0)) as taxa,
                SUM('.ContributionMargin::custoSql('oi', 'p').') as custo,
                SUM(CASE WHEN p.cost_price IS NULL AND oi.manual_cost_price IS NULL THEN 1 ELSE 0 END) as sem_custo')
            ->groupBy('l.external_id')
            ->get();

        $aliquota = (float) config('loja.simples.aliquota', 4.5) / 100;
        $resultado = [];
        foreach ($linhas as $l) {
            $unidades = max(1, (int) $l->unidades);
            $receita = (float) $l->receita;
            $imposto = $receita * $aliquota;
            $lucro = $receita - (float) $l->taxa - (float) $l->custo - $imposto;
            $resultado[(string) $l->external_id] = self::indicadores(
                $receita / $unidades,
                $lucro / $unidades,
                (float) $l->custo / $unidades,
                $receita > 0 ? (float) $l->taxa / $receita : 0.0,
            ) + [
                'pedidos' => (int) $l->pedidos,
                'unidades' => (int) $l->unidades,
                'receita' => round($receita, 2),
                'taxa' => round((float) $l->taxa, 2),
                'custo' => round((float) $l->custo, 2),
                'imposto' => round($imposto, 2),
                'sem_custo' => (int) $l->sem_custo > 0,
            ];
        }

        return $resultado;
    }

    /** Taxa média real do canal no período (para estimar anúncio sem venda). */
    public function taxaMedia(string $canal, Carbon $desde): float
    {
        $t = DB::table('orders as o')
            ->leftJoin('order_channel_fees as f', 'f.order_id', '=', 'o.id')
            ->where('o.origin', $canal)
            ->whereIn('o.status', ContributionMargin::REVENUE_STATUSES)
            ->where('o.created_at', '>=', $desde)
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q, 'o'))
            ->selectRaw('COALESCE(SUM(f.fee_amount), 0) as taxa, COALESCE(SUM(o.subtotal), 0) as receita')
            ->first();

        return (float) $t->receita > 0 ? (float) $t->taxa / (float) $t->receita : 0.2;
    }

    /** Estimativa para anúncio sem venda no período: preço do anúncio, custo do cadastro e taxa média do canal. */
    public function estimar(float $preco, ?float $custo, float $taxaMedia): array
    {
        $aliquota = (float) config('loja.simples.aliquota', 4.5) / 100;
        $lucro = $preco - $preco * $taxaMedia - $preco * $aliquota - (float) $custo;

        return self::indicadores($preco, $lucro, (float) $custo, $taxaMedia) + ['sem_custo' => $custo === null, 'estimado' => true];
    }

    /**
     * preco_minimo: o preço em que sobram 15% depois de custo, taxa (em %) e
     * imposto, sem ADS. Taxa fixa por pedido entra aproximada na taxa média.
     *
     * @return array{preco: float, lucro_unidade: float, margem: float, roas_empate: ?float, roas_meta: ?float, preco_minimo: ?float}
     */
    public static function indicadores(float $preco, float $lucroUnidade, float $custoUnidade = 0.0, float $taxaPct = 0.0): array
    {
        $folga = $lucroUnidade - self::MARGEM_ALVO * $preco;
        $aliquota = (float) config('loja.simples.aliquota', 4.5) / 100;
        $sobra = 1 - $taxaPct - $aliquota - self::MARGEM_ALVO;

        return [
            'preco' => round($preco, 2),
            'lucro_unidade' => round($lucroUnidade, 2),
            'margem' => $preco > 0 ? round($lucroUnidade / $preco * 100, 1) : 0.0,
            'roas_empate' => $lucroUnidade > 0 ? round($preco / $lucroUnidade, 1) : null,
            'roas_meta' => $folga > 0 ? round($preco / $folga, 1) : null,
            'preco_minimo' => $custoUnidade > 0 && $sobra > 0 ? round($custoUnidade / $sobra, 2) : null,
        ];
    }
}
