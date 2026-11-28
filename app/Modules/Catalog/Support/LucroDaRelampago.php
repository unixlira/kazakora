<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Support\DescontoPix;

/**
 * Oferta Relâmpago com lucro líquido fixo (pedido do Lira em 27/11/2026:
 * "as ofertas relâmpago mas lá quero lucro líquido de 22%").
 *
 * Margem de contribuição de 1 unidade, com as peças da ContributionMargin
 * (imposto = aliquotaSimples(), insumos = insumosPorPedido()) e as taxas do
 * pagamento de config/ofertas.php, no pior caso entre cartão e Pix:
 *
 *   venda  = preço do anúncio na oferta + frete embutido (F)
 *   lucro  = venda × (1 − imposto − taxa do pagamento no pior caso)
 *            − custo − frete pago aos Correios (F) − insumos
 *   margem = lucro / venda
 *
 * O frete varia com a região do cliente; a conta usa ofertas.frete_estimado
 * (R$ 25, o mesmo pior caso da Oferta do Dia) como frete embutido e como
 * frete pago. Pix: o "Desconto Checkout" do produto sai antes da taxa.
 *
 * precoParaMargem() devolve o desconto (arredondado para BAIXO, nunca dá
 * menos que a margem pedida) que deixa a margem certa; null quando o produto
 * não tem custo ou quando nem o preço atual chega à margem (sem desconto).
 */
class LucroDaRelampago
{
    public const MARGEM_PADRAO = 22.0;

    /** Fatia da venda que sobra depois do imposto e da taxa do pagamento, no pior caso. */
    public static function fatia(Product $produto): float
    {
        $imposto = ContributionMargin::aliquotaSimples() / 100;
        $cartao = (float) config('ofertas.taxa_cartao', 4.98) / 100;
        $taxaPix = (float) config('ofertas.taxa_pix', 0.99) / 100;
        $pix = DescontoPix::percentualDo($produto, $produto->precoSemOferta()) / 100;

        return min(1 - $cartao, (1 - $pix) * (1 - $taxaPix)) - $imposto;
    }

    public static function frete(): float
    {
        return (float) config('ofertas.frete_estimado', 25);
    }

    /** Custos fixos da unidade: custo do produto, frete pago e insumos. */
    private static function gastos(Product $produto): float
    {
        return self::custo($produto) + self::frete() + ContributionMargin::insumosPorPedido();
    }

    public static function custo(Product $produto): float
    {
        $custo = (float) $produto->cost_price;
        if ($custo <= 0 && $produto->parent_product_id) {
            $custo = (float) Product::query()->whereKey($produto->parent_product_id)->value('cost_price');
        }

        return $custo;
    }

    /**
     * Lucro e margem de 1 unidade com o preço do anúncio $preco (sem frete).
     *
     * @return array{venda: float, lucro: float, margem: float}|null null sem custo
     */
    public static function conta(Product $produto, float $preco): ?array
    {
        if (self::custo($produto) <= 0 || $preco <= 0) {
            return null;
        }

        $venda = $preco + self::frete();
        $lucro = round($venda * self::fatia($produto) - self::gastos($produto), 2);

        return ['venda' => round($venda, 2), 'lucro' => $lucro, 'margem' => round($lucro / $venda * 100, 2)];
    }

    /**
     * Desconto da Relâmpago para a margem pedida (padrão 22%).
     *
     * @return array{desconto_pct: float, preco_oferta: float, lucro: float, margem: float}|null
     */
    public static function precoParaMargem(Product $produto, float $margem = self::MARGEM_PADRAO): ?array
    {
        $atual = $produto->precoSemOferta();
        $sobra = self::fatia($produto) - $margem / 100;
        if (self::custo($produto) <= 0 || $atual <= 0 || $sobra <= 0) {
            return null;
        }

        $venda = self::gastos($produto) / $sobra;
        $alvo = $venda - self::frete();
        // Para baixo no centésimo de %: o preço fica igual ou acima do alvo.
        $desconto = floor((1 - $alvo / $atual) * 10000) / 100;
        if ($desconto < 1) {
            return null;
        }

        $desconto = min(90.0, $desconto);
        // O centavo do arredondamento do preço nunca pode deixar a margem abaixo da pedida.
        do {
            $preco = max(0, round($atual * (1 - $desconto / 100), 2));
            $conta = self::conta($produto, $preco);
            if ($conta['margem'] >= $margem) {
                break;
            }
            $desconto = round($desconto - 0.01, 2);
        } while ($desconto >= 1);
        if ($desconto < 1) {
            return null;
        }

        return ['desconto_pct' => $desconto, 'preco_oferta' => $preco, 'lucro' => $conta['lucro'], 'margem' => $conta['margem']];
    }
}
