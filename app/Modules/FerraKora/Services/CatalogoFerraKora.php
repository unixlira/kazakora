<?php

namespace App\Modules\FerraKora\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo do FerraKora (loja de ferramentas, pedido 2026-10-13), no mesmo
 * formato do Beauty Kora (CatalogoOutroSite) e mais dois campos:
 * "categoria" (para o menu do site) e "kazakora_url" (página do produto na
 * loja KazaKora, onde o cliente compra).
 */
class CatalogoFerraKora extends CatalogoOutroSite
{
    protected function modelo(): string
    {
        return FerraKoraProduto::class;
    }

    protected function camposDoItem(array $item): array
    {
        return ['categoria' => filled($item['categoria'] ?? null) ? trim((string) $item['categoria']) : null];
    }

    protected function camposExtras(Model $registro, Product $pai): array
    {
        return [
            'categoria' => $registro->categoria,
            'kazakora_url' => filled($pai->slug) ? route('produtos.ver', $pai->slug) : null,
        ];
    }
}
