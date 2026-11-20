<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing › Vids (2026-11-20): a Máquina de Criativos do Lira dentro do admin.
 * O painel da Máquina troca o campo "link da página" por uma busca de produtos
 * (pedido do Lira 20/11: igual à busca do site, porque o título é persuasivo e
 * não traz o tipo do produto). Ele pede a busca por postMessage e esta tela
 * consulta /admin/vids/produtos, que usa a mesma regra da busca do site.
 */
class VidsController extends Controller
{
    private const LIMITE = 20;

    public function index(): Response
    {
        return Inertia::render('Admin/Vids/Index');
    }

    public function produtos(Request $request): JsonResponse
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        $produtos = $this->base()
            ->when($q !== '', function (Builder $query) use ($q) {
                CatalogController::filtrarBusca($query, $q);
                // Quem tem a palavra no nome vem na frente (igual ao site).
                $primeira = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) strtok($q, ' ')).'%';
                $query->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$primeira]);
            })
            ->orderBy('name')
            ->limit(self::LIMITE)
            ->get(['id', 'name', 'slug'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'nome' => $p->name,
                'url' => route('produtos.ver', $p->slug),
                'foto' => $p->images->first()?->thumb_url,
            ])
            ->values();

        return response()->json(['q' => $q, 'produtos' => $produtos]);
    }

    private function base(): Builder
    {
        return Product::query()
            ->where('is_active', true)
            ->whereNull('parent_product_id')
            ->where('so_ferrakora', false)
            ->whereNotNull('slug')
            ->with(['images' => fn ($q) => $q->orderBy('position')]);
    }
}
