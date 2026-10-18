<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;

/**
 * Lucro de 1 unidade vendida na loja (pedido 2026-10-18), para o cadastro
 * do produto e para o limite do "Desconto Checkout" no Pix:
 * recebido − imposto do Simples − taxa do pagamento − custo do produto.
 * Recebido = preço de venda (já com o Desconto Site) menos o desconto do Pix.
 * Taxas: as mesmas da conta da oferta do dia (config/ofertas.php).
 *
 * "Lucro Mínimo" vazio = R$ 10 para preço acima de R$ 30, R$ 8 até R$ 30.
 */
class LucroProduto
{
    /** Imposto padrão, taxa do Pix e do cartão, em %. */
    public static function taxas(): array
    {
        return [
            'imposto' => (float) config('loja.simples.aliquota', 4.5),
            'taxa_pix' => (float) config('ofertas.taxa_pix', 0.99),
            'taxa_cartao' => (float) config('ofertas.taxa_cartao', 4.98),
        ];
    }

    public static function lucroMinimo(Product $produto, float $preco): float
    {
        if ($produto->min_profit !== null && $produto->min_profit !== '') {
            return (float) $produto->min_profit;
        }

        return $preco > 30 ? 10.0 : 8.0;
    }

    /** Lucro de 1 unidade recebendo $recebido, com a taxa (%) do meio de pagamento. */
    public static function lucro(Product $produto, float $recebido, float $taxa): float
    {
        return round($recebido * (1 - (self::taxas()['imposto'] + $taxa) / 100) - (float) $produto->cost_price, 2);
    }

    /**
     * Maior desconto no Pix (R$ por unidade) que ainda deixa o Lucro Mínimo
     * vendendo a $preco. Sem custo cadastrado não há conta: sem limite.
     */
    public static function descontoPixMaximo(Product $produto, float $preco): ?float
    {
        if ((float) $produto->cost_price <= 0) {
            return null;
        }

        $taxas = self::taxas();
        $fator = 1 - ($taxas['imposto'] + $taxas['taxa_pix']) / 100;
        $maximo = $preco - (self::lucroMinimo($produto, $preco) + (float) $produto->cost_price) / $fator;

        // Para baixo no centavo: arredondar para cima já deixaria abaixo do mínimo.
        return max(0.0, floor(round($maximo * 100, 6)) / 100);
    }
}
