<?php

namespace App\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * Desconto no Pix da loja, só no checkout. Desde 2026-10-18 é por produto:
 * campo "Desconto Checkout" (products.checkout_discount_percentage em % ou
 * checkout_discount_amount em R$), sem percentual fixo no código. Vazio =
 * sem desconto no Pix. O preço da loja é
 * o valor do cadastro, sem acréscimo.
 */
class DescontoPix
{
    /**
     * "Desconto Checkout" do produto em %. Desde 2026-10-18 pode ser em valor
     * fixo (checkout_discount_amount, R$ por unidade): vira o % equivalente
     * sobre o preço da unidade ($preco; sem ele, o final_price da loja).
     */
    public static function percentualDo(?Product $produto, ?float $preco = null): float
    {
        if (! $produto) {
            return 0.0;
        }

        return self::percentualConfigurado($produto, $preco);
    }

    /** Desconto do Pix em R$ numa unidade vendida a $preco. */
    public static function descontoUnitario(Product $produto, float $preco): float
    {
        return self::desconto($preco, self::percentualDo($produto, $preco));
    }

    /**
     * "Desconto Checkout" como foi cadastrado, em %. Desde 2026-10-18 pode ser
     * em valor fixo (checkout_discount_amount, R$ por unidade): vira o %
     * equivalente sobre o preço da unidade ($preco; sem ele, o final_price da loja).
     */
    private static function percentualConfigurado(Product $produto, ?float $preco): float
    {
        if ((float) $produto->checkout_discount_percentage <= 0 && (float) $produto->checkout_discount_amount > 0) {
            $preco ??= (float) $produto->final_price;

            return $preco > 0 ? min(100.0, (float) $produto->checkout_discount_amount * 100 / $preco) : 0.0;
        }

        return min(100.0, max(0.0, (float) $produto->checkout_discount_percentage));
    }

    /**
     * % do Pix de um carrinho: média dos "Desconto Checkout" dos itens,
     * pesada pelo subtotal de cada um. Itens: [product, subtotal, quantity].
     * 4 casas: o desconto em R$ fixo bate no centavo.
     */
    public static function percentualDosItens(Collection $itens): float
    {
        $subtotal = (float) $itens->sum('subtotal');

        if ($subtotal <= 0) {
            return 0.0;
        }

        $pesado = $itens->sum(function ($item) {
            $quantidade = max(1, (int) ($item['quantity'] ?? 1));

            return (float) $item['subtotal'] * self::percentualDo($item['product'] ?? null, (float) $item['subtotal'] / $quantidade);
        });

        return round($pesado / $subtotal, 4);
    }

    /** Quanto sai de desconto pagando no Pix sobre um valor, a um percentual. */
    public static function desconto(float $valor, float $percentual): float
    {
        return round($valor * max(0.0, $percentual) / 100, 2);
    }

    /** Maior "Desconto Checkout" entre os produtos ativos (chamada "até X% no Pix"). */
    public static function maiorPercentual(): float
    {
        $percentual = (float) Product::query()->where('is_active', true)->max('checkout_discount_percentage');
        // Valor fixo entra pelo % equivalente sobre o preço do cadastro.
        $fixo = (float) Product::query()->where('is_active', true)
            ->where('checkout_discount_amount', '>', 0)->where('price', '>', 0)
            ->selectRaw('MAX(checkout_discount_amount * 100 / price) as maior')->value('maior');

        return round(max($percentual, min(100.0, $fixo)), 2);
    }
}
