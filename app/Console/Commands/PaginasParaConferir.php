<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use Illuminate\Console\Command;

/**
 * Lista os caminhos que o deploy de produção abre logo depois de trocar a
 * versão: home, carrinho e páginas de produto reais (uma com conteúdo de
 * anúncio, que monta a faixa do topo, e a mais recente).
 *
 * Por quê (24/11/2026): a faixa do topo usava uma regex que o PCRE do
 * servidor não conhece. O /up respondia 200, mas TODA página de produto dava
 * 500, e o deploy deixou a versão no ar por 9 minutos.
 */
class PaginasParaConferir extends Command
{
    protected $signature = 'loja:paginas-para-conferir';

    protected $description = 'Imprime, um por linha, os caminhos que o deploy confere depois de subir';

    public function handle(): int
    {
        $caminhos = ['/', '/carrinho'];

        $comConteudo = ProductAdContent::query()->pluck('product_id')->all();
        $produtos = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('so_ferrakora', false)->orWhereNull('so_ferrakora'))
            ->latest('id')
            ->get(['id', 'slug']);

        $escolhidos = collect([
            $produtos->first(fn ($p) => in_array($p->id, $comConteudo, true)),
            $produtos->first(),
        ])->filter()->unique('id');

        foreach ($escolhidos as $produto) {
            $caminhos[] = '/produtos/'.$produto->slug;
        }

        foreach ($caminhos as $caminho) {
            $this->line($caminho);
        }

        return self::SUCCESS;
    }
}
