<?php

namespace App\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * Desconto no Pix da loja, só no checkout. Desde 2026-10-18 é por produto:
 * campo "Desconto Checkout" do cadastro, Percentual
 * (products.checkout_discount_percentage) ou Valor fixo em R$ por unidade
 * (products.checkout_discount_amount), conforme checkout_discount_type.
 * Vazio = sem desconto no Pix. O preço da loja é o valor do cadastro, sem
 * acréscimo.
 */
class DescontoPix
{
    public const PERCENTUAL = 'percentage';

    public const FIXO = 'amount';

    public static function ehFixo(?Product $produto): bool
    {
        return $produto?->checkout_discount_type === self::FIXO;
    }

    /** "Desconto Checkout" do produto em R$ sobre um preço unitário, para uma quantidade. */
    public static function descontoDo(?Product $produto, float $preco, int $quantidade = 1): float
    {
        if (! $produto || $preco <= 0) {
            return 0.0;
        }

        $valor = $preco * max(1, $quantidade);

        if (self::ehFixo($produto)) {
            return round(min($valor, max(0.0, (float) $produto->checkout_discount_amount) * max(1, $quantidade)), 2);
        }

        return self::desconto($valor, min(100.0, max(0.0, (float) $produto->checkout_discount_percentage)));
    }

    /**
     * "Desconto Checkout" do produto em %. No Valor fixo, é o % que o valor
     * representa no preço (o da loja, se não vier outro).
     */
    public static function percentualDo(?Product $produto, ?float $preco = null): float
    {
        if (! $produto) {
            return 0.0;
        }

        if (! self::ehFixo($produto)) {
            return min(100.0, max(0.0, (float) $produto->checkout_discount_percentage));
        }

        $preco ??= (float) $produto->final_price;

        return $preco > 0 ? round(self::descontoDo($produto, $preco) * 100 / $preco, 2) : 0.0;
    }

    /**
     * % do Pix de um carrinho: soma dos "Desconto Checkout" dos itens (em R$,
     * Percentual ou Valor fixo × quantidade) sobre o subtotal. 4 casas para o
     * Valor fixo bater no centavo. Itens: [product, quantity, subtotal].
     */
    public static function percentualDosItens(Collection $itens): float
    {
        $subtotal = (float) $itens->sum('subtotal');

        if ($subtotal <= 0) {
            return 0.0;
        }

        $desconto = $itens->sum(function ($item) {
            $quantidade = max(1, (int) ($item['quantity'] ?? 1));

            return self::descontoDo($item['product'] ?? null, (float) $item['subtotal'] / $quantidade, $quantidade);
        });

        return round($desconto * 100 / $subtotal, 4);
    }

    /** Quanto sai de desconto pagando no Pix sobre um valor, a um percentual. */
    public static function desconto(float $valor, float $percentual): float
    {
        return round($valor * max(0.0, $percentual) / 100, 2);
    }

    /** Maior "Desconto Checkout" percentual entre os produtos ativos (chamada "até X% no Pix"). */
    public static function maiorPercentual(): float
    {
        return (float) Product::query()->where('is_active', true)
            ->where('checkout_discount_type', self::PERCENTUAL)
            ->max('checkout_discount_percentage');
    }
}
