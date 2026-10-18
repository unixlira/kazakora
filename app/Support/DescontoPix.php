<?php

namespace App\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * Desconto no Pix da loja, só no checkout. Desde 2026-10-18 é por produto:
 * campo "Desconto Checkout" (products.checkout_discount_percentage ou, em
 * valor fixo, checkout_discount_amount), sem percentual fixo no código.
 * Vazio = sem desconto no Pix. O preço da loja é o valor do cadastro, sem
 * acréscimo.
 */
class DescontoPix
{
    /**
     * "Desconto Checkout" do produto em %. Quando o cadastro é em valor fixo
     * (R$ por unidade, 2026-10-18), vira o % equivalente sobre o preço da loja.
     */
    public static function percentualDo(?Product $produto): float
    {
        if (! $produto) {
            return 0.0;
        }

        $valor = (float) $produto->checkout_discount_amount;

        if ($valor > 0) {
            $preco = (float) $produto->final_price;

            return $preco > 0 ? round(min(100.0, $valor * 100 / $preco), 4) : 0.0;
        }

        return min(100.0, max(0.0, (float) $produto->checkout_discount_percentage));
    }

    /**
     * % do Pix de um carrinho: média dos "Desconto Checkout" dos itens,
     * pesada pelo subtotal de cada um. Itens: [product, subtotal].
     */
    public static function percentualDosItens(Collection $itens): float
    {
        $subtotal = (float) $itens->sum('subtotal');

        if ($subtotal <= 0) {
            return 0.0;
        }

        $pesado = $itens->sum(fn ($item) => (float) $item['subtotal'] * self::percentualDo($item['product'] ?? null));

        return round($pesado / $subtotal, 2);
    }

    /** Quanto sai de desconto pagando no Pix sobre um valor, a um percentual. */
    public static function desconto(float $valor, float $percentual): float
    {
        return round($valor * max(0.0, $percentual) / 100, 2);
    }

    /** Maior "Desconto Checkout" entre os produtos ativos (chamada "até X% no Pix"). */
    public static function maiorPercentual(): float
    {
        $ativos = fn () => Product::query()->where('is_active', true);

        $emValor = (float) $ativos()->where('checkout_discount_amount', '>', 0)->where('price', '>', 0)
            ->selectRaw('max(checkout_discount_amount * 100.0 / price) as maior')->value('maior');

        return round(min(100.0, max((float) $ativos()->max('checkout_discount_percentage'), $emValor)), 2);
    }
}
