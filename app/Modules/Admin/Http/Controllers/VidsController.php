<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing › Vids (2026-11-20): a Máquina de Criativos do Lira dentro do admin.
 * A tela manda a lista de produtos da loja para o painel da Máquina, que troca o
 * campo "link da página" por um select desses produtos.
 */
class VidsController extends Controller
{
    public function index(): Response
    {
        $produtos = Product::query()
            ->where('is_active', true)
            ->whereNull('parent_product_id')
            ->where('so_ferrakora', false)
            ->whereNotNull('slug')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'url' => route('produtos.ver', $p->slug),
            ])
            ->values();

        return Inertia::render('Admin/Vids/Index', ['produtos' => $produtos]);
    }
}
