<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;

/**
 * Qual layout a página do produto usa (pedido do Lira 24/11/2026).
 *
 * - "brinquedo": página no estilo de loja infantil (kits em cartões com selo e
 *   preço por unidade, seções coloridas, garantia, FAQ e o "aeee" ao abrir).
 * - "padrao": a página de sempre (ProductDetailV2 sem nada a mais).
 *
 * O produto (ou o pai dele, para as variações) pode forçar um dos dois pela
 * coluna products.layout_pagina; vazio, vale a categoria.
 */
class LayoutDaPagina
{
    public const BRINQUEDO = 'brinquedo';

    public const PADRAO = 'padrao';

    /** Categorias que abrem no layout de brinquedo quando o produto não escolhe. */
    public const CATEGORIAS_BRINQUEDO = ['brinquedos-e-games'];

    public static function para(Product $product): string
    {
        $escolhido = $product->layout_pagina ?: $product->parent?->layout_pagina;
        if (in_array($escolhido, [self::BRINQUEDO, self::PADRAO], true)) {
            return $escolhido;
        }

        $categoria = $product->category?->slug ?? $product->parent?->category?->slug;

        return in_array($categoria, self::CATEGORIAS_BRINQUEDO, true) ? self::BRINQUEDO : self::PADRAO;
    }
}
