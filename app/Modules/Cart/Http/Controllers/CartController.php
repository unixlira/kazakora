<?php

namespace App\Modules\Cart\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Cart\Support\CartManager;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(private readonly CartManager $cart)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Cart/Index', [
            'items' => $this->cart->items(),
            'total' => $this->cart->total(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);

        if (! $product->is_active || $product->stock < 1) {
            return back()->withErrors(['product_id' => 'Produto indisponível.']);
        }

        $this->cart->add($product->id, min($data['quantity'], $product->stock));

        return back()->with('success', 'Produto adicionado ao carrinho.');
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $this->cart->update($product, $data['quantity']);

        return back();
    }

    public function destroy(int $product): RedirectResponse
    {
        $this->cart->remove($product);

        return back();
    }

    /** Botão "Finalizar minha compra" do e-mail de carrinho abandonado. */
    public function recuperar(int $carrinho): RedirectResponse
    {
        $snapshot = CartSnapshot::find($carrinho);

        if (! $snapshot) {
            return redirect()->route('carrinho.ver')->with('warning', 'Esse carrinho não existe mais — talvez você já tenha finalizado a compra.');
        }

        $this->cart->restaurar($snapshot);

        return redirect()->route('carrinho.ver');
    }

    /** "Não quero mais receber lembretes deste carrinho". */
    public function pararLembretes(int $carrinho): RedirectResponse
    {
        CartSnapshot::query()->whereKey($carrinho)->update(['lembretes_parados' => true]);

        return redirect()->route('catalogo.inicio')->with('success', 'Pronto! Não vamos mais mandar lembretes desse carrinho.');
    }
}
