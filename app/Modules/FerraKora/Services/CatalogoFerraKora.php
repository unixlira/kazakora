<?php

namespace App\Modules\FerraKora\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Support\DescontoPix;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo do FerraKora (loja de ferramentas, pedido 2026-10-13), no mesmo
 * formato do Beauty Kora (CatalogoOutroSite) e mais dois campos:
 * "categoria" (para o menu do site) e "kazakora_url" (página do produto na
 * loja KazaKora, onde o cliente compra).
 *
 * Preço com o frete embutido da região do visitante (2026-10-13): o mesmo
 * final_price da página do produto no KazaKora, para o cliente não ver um
 * preço no FerraKora e outro ao clicar em Comprar. "frete_gratis" diz ao
 * site que o frete já está no preço; "desconto_pix" é o % que o checkout do
 * KazaKora tira no Pix.
 */
class CatalogoFerraKora extends CatalogoOutroSite
{
    protected function modelo(): string
    {
        return FerraKoraProduto::class;
    }

    protected function comFreteEmbutido(): bool
    {
        return true;
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
            'frete_gratis' => app(FreteEmbutido::class)->ligado(),
            'desconto_pix' => DescontoPix::percentual(),
        ];
    }
}
