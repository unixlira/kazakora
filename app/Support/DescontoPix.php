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

    /**
     * Desconto do Pix desde 2026-10-24: o % sobre os produtos ANTES do cupom
     * (3% do BEMVINDO + 2% do Pix = 5%), sem passar do que sobrou depois do
     * cupom. $base = produtos menos o desconto da caixa única.
     */
    public static function descontoSobreCupom(float $base, float $descontoCupom, float $percentual): float
    {
        return min(self::desconto(max(0.0, $base), $percentual), round(max(0.0, $base - $descontoCupom), 2));
    }

    /**
     * Quanto o cliente paga no Pix por $quantidade unidades a $preco cada
     * (sem preço: final_price da loja), com a mesma conta do checkout:
     * % do carrinho com 4 casas e desconto arredondado no centavo. Null =
     * produto sem desconto no Pix. Página de produto (pedido 2026-10-23).
     * Desde 2026-10-24 soma o % do cupom automático (BEMVINDO), que o
     * checkout já aplica sozinho: 2% do Pix + 3% do cupom = 5%.
     */
    public static function precoPix(?Product $produto, ?float $preco = null, int $quantidade = 1): ?float
    {
        if (! $produto) {
            return null;
        }

        $preco ??= (float) $produto->final_price;
        $percentual = round(self::percentualDo($produto, $preco), 4);

        if ($percentual <= 0 || $preco <= 0) {
            return null;
        }

        $percentual = min(100.0, $percentual + CupomAutomatico::percentualNaVitrine($produto));

        $subtotal = round($preco * max(1, $quantidade), 2);

        return round($subtotal - self::desconto($subtotal, $percentual), 2);
    }

    /**
     * % que a vitrine mostra no Pix (catálogo dos outros sites): o "Desconto
     * Checkout" do produto mais o cupom automático (2% + 3% = 5%, 2026-10-24).
     */
    public static function percentualNaVitrine(?Product $produto): float
    {
        $percentual = self::percentualDo($produto);

        return $percentual > 0 ? min(100.0, round($percentual + CupomAutomatico::percentualNaVitrine($produto), 4)) : 0.0;
    }

    /** Maior "Desconto Checkout" entre os produtos ativos (chamada "até X% no Pix"). */
    public static function maiorPercentual(): float
    {
        return (float) Product::query()->where('is_active', true)->max('checkout_discount_percentage');
    }
}
