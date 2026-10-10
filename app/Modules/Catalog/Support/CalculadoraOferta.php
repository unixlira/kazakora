<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Support\DescontoPix;

/**
 * Conta de lucro da oferta do dia (pedido 2026-10-10: "nunca dar prejuízo").
 * Pior caso entre cartão (taxa maior) e Pix (preço com o desconto do Pix):
 * recebido líquido − custo do produto − frete grátis pago pela loja.
 */
class CalculadoraOferta
{
    /** Preço com os pontos extras: tira X% do preço "de" a mais do desconto atual. */
    public static function precoOferta(Product $product, float $extra): float
    {
        return max(0, round($product->precoSemOferta() - (float) $product->price * $extra / 100, 2));
    }

    /** Lucro no pior caso para um preço de venda na loja. */
    public static function lucroPiorCaso(Product $product, float $preco): float
    {
        $custo = (float) $product->cost_price + (float) config('ofertas.frete_estimado');
        $cartao = $preco * (1 - config('ofertas.taxa_cartao') / 100);
        $pix = ($preco - DescontoPix::desconto($preco)) * (1 - config('ofertas.taxa_pix') / 100);

        return round(min($cartao, $pix) - $custo, 2);
    }

    /** A oferta pode entrar? Exige custo cadastrado e a margem mínima sobrando. */
    public static function seguro(Product $product, float $preco): bool
    {
        if ((float) $product->cost_price <= 0 || $preco <= 0) {
            return false;
        }

        return self::lucroPiorCaso($product, $preco) >= $preco * config('ofertas.margem_minima') / 100;
    }

    /** Desconto atual (sem oferta) em % do preço "de". */
    public static function descontoAtual(Product $product): float
    {
        $price = (float) $product->price;

        return $price > 0 ? round((1 - $product->precoSemOferta() / $price) * 100, 2) : 0.0;
    }
}
