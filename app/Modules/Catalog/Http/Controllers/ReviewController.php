<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\SolicitacaoAvaliacao;
use App\Modules\Checkout\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product): RedirectResponse
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
        ]);

        Review::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            $validated,
        );
        SolicitacaoAvaliacao::marcarAvaliado($request->user()->id, $product->id);

        return back()->with('success', 'Avaliação enviada, obrigado!');
    }

    /**
     * Link "Avaliar" do e-mail pós-compra: exige login (quem não está logado
     * entra e volta para cá) e abre a página do produto com a janela de
     * avaliação aberta.
     */
    public function avaliar(Product $product): RedirectResponse
    {
        return redirect()->to(route('produtos.ver', $product->slug).'?avaliar=1#avaliacoes');
    }
}
