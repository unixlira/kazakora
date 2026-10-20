<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\ClubeDeAvaliacoes;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    /**
     * Esboço da tela "Avalie suas compras" (pedido 2026-10-20): cada produto
     * já entregue ao cliente, com a avaliação dele se já existir, e o Clube
     * de Avaliações (pontos, níveis e cupons ganhos).
     */
    public function index(Request $request, ClubeDeAvaliacoes $clube): Response
    {
        $user = $request->user();

        $itens = OrderItem::query()
            ->whereNotNull('product_id')
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id)->where('status', Order::STATUS_COMPLETED))
            ->with(['order:id,external_order_id,updated_at', 'product:id,name,slug,parent_product_id', 'product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')])
            ->latest('id')
            ->get()
            ->unique('product_id');

        $avaliacoes = Review::query()
            ->where('user_id', $user->id)
            ->whereIn('product_id', $itens->pluck('product_id'))
            ->get(['product_id', 'rating', 'comment', 'ocultar_nome', 'updated_at'])
            ->keyBy('product_id');

        $produtos = $itens->filter(fn (OrderItem $item) => $item->product)->map(fn (OrderItem $item) => [
            'id' => $item->product_id,
            'nome' => $item->product_name ?: $item->product->name,
            'slug' => $item->product->slug,
            'imagem' => $item->product->images->first()?->thumb_url,
            'pedido' => $item->order->numero,
            'entregue_em' => $item->order->updated_at?->toIso8601String(),
            'avaliacao' => ($a = $avaliacoes->get($item->product_id)) ? [
                'nota' => (int) $a->rating,
                'comentario' => $a->comment,
                'ocultar_nome' => (bool) $a->ocultar_nome,
            ] : null,
        ])->values();

        return Inertia::render('Catalog/Avaliar', [
            'produtos' => $produtos,
            'clube' => $clube->resumo($user),
            'nomeCliente' => $user->name,
        ]);
    }

    public function store(Request $request, Product $product, ClubeDeAvaliacoes $clube): RedirectResponse
    {
        $purchased = $request->user()->orders()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->exists();

        if (! $purchased) {
            return back()->withErrors(['review' => 'Você só pode avaliar produtos que já recebeu.']);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'ocultar_nome' => ['sometimes', 'boolean'],
        ]);

        Review::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            $validated,
        );

        // Clube de Avaliações: nível novo gera o cupom pessoal na hora.
        $novos = $clube->conceder($request->user());
        if ($novos->isNotEmpty()) {
            $codigos = $novos->map(fn ($cupom) => "{$cupom->code} ({$cupom->descricaoDesconto()})")->join(', ', ' e ');

            return back()->with('success', "Avaliação enviada, obrigado! Você ganhou o cupom {$codigos}. Ele fica guardado em Avalie suas compras.");
        }

        return back()->with('success', 'Avaliação enviada, obrigado!');
    }
}
