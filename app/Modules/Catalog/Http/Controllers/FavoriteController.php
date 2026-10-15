<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FavoriteController extends Controller
{
    /** Teto da lista guardada no navegador de quem não entrou. */
    private const LIMITE = 200;

    public function index(Request $request): Response
    {
        if (! $request->user()) {
            return $this->paginaDoVisitante($request);
        }

        $products = Product::query()
            ->forCard()
            ->whereHas('favorites', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(12);

        $purchasedProductIds = OrderItem::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', Order::STATUS_COMPLETED))
            ->pluck('product_id')
            ->unique()
            ->values();

        // Pedido explícito 2026-08-17 (variações de produto): comprou a
        // variação "10 Polegadas" tem que poder avaliar a variação "8
        // Polegadas" favoritada — mesmo item físico. Expande cada id
        // comprado pro grupo de variações inteiro (variantGroupIds()
        // devolve só o próprio id quando o produto não tem variação
        // nenhuma, sem regressão pro caso comum).
        $reviewableProductIds = Product::query()
            ->whereIn('id', $purchasedProductIds)
            ->get(['id', 'parent_product_id'])
            ->flatMap(fn (Product $product) => $product->variantGroupIds())
            ->unique()
            ->values();

        return Inertia::render('Catalog/Favoritos', [
            'products' => $products,
            'favoriteIds' => Favorite::query()->where('user_id', $request->user()->id)->pluck('product_id'),
            'reviewableProductIds' => $reviewableProductIds,
            'reviewedProductIds' => Review::query()->where('user_id', $request->user()->id)->pluck('product_id'),
        ]);
    }

    /**
     * Visitante sem login (pedido 2026-10-15): os favoritos ficam no
     * navegador e chegam aqui como ?ids=1,2,3, do mais recente ao mais antigo.
     */
    private function paginaDoVisitante(Request $request): Response
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->take(self::LIMITE)
            ->values();

        $products = Product::query()
            ->forCard()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Catalog/Favoritos', [
            'products' => $products,
            'favoriteIds' => $ids,
            'reviewableProductIds' => [],
            'reviewedProductIds' => [],
            'visitante' => true,
        ]);
    }

    /**
     * Ao entrar, o que foi guardado no navegador sem login se junta à conta.
     * Devolve a lista da conta, mais recente primeiro.
     */
    public function sincronizar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'produtos' => ['array', 'max:'.self::LIMITE],
            'produtos.*' => ['integer'],
        ]);

        $userId = $request->user()->id;
        $existentes = Product::query()->whereIn('id', $dados['produtos'] ?? [])->pluck('id');

        foreach ($existentes as $productId) {
            Favorite::query()->firstOrCreate(['user_id' => $userId, 'product_id' => $productId]);
        }

        return response()->json(['ids' => Favorite::idsDoUsuario($userId)]);
    }

    public function toggle(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $favorite = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->first();

        if ($favorite) {
            $favorite->delete();
        } else {
            Favorite::create([
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
            ]);
        }

        // O botão da loja chama por fetch e mostra o próprio aviso (laranja).
        if ($request->expectsJson()) {
            return response()->json(['favorito' => ! $favorite, 'ids' => Favorite::idsDoUsuario($request->user()->id)]);
        }

        return back()->with('success', $favorite ? 'Produto removido dos favoritos.' : 'Produto adicionado aos favoritos.');
    }
}
