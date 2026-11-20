<?php

namespace App\Modules\Cart\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Cart\Support\CartManager;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\EnviarEventoMetaJob;
use App\Services\Meta\ConversoesMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(private readonly CartManager $cart)
    {
    }

    public function index(): Response
    {
        // Abriu o carrinho no meio de uma compra direta ("Alterar itens do
        // carrinho"): o item dela vai para o carrinho normal, não some.
        $this->cart->levarCompraDiretaParaCarrinho();

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

        if (! $product->naKazaKora() || $product->stock < 1) {
            return back()->withErrors(['product_id' => 'Produto indisponível.']);
        }

        $quantidade = min($data['quantity'], $product->stock);
        $this->cart->add($product->id, $quantidade);
        $this->addToCartNaMeta($request, $product, $quantidade);
        $this->registrarNoFunil($request, $product->id);

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

    /**
     * "Comprar" na página do produto (pedido 2026-10-11): vai direto para a
     * finalização só com este item, sem mexer no carrinho.
     */
    public function comprarAgora(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::query()->naKazaKora()->findOrFail($data['product_id']);

        if ($product->stock < 1) {
            return back()->withErrors(['cart' => 'Produto esgotado.']);
        }

        $quantidade = min($data['quantity'], $product->stock);
        $this->cart->iniciarCompraDireta($product->id, $quantidade);
        $this->addToCartNaMeta($request, $product, $quantidade);
        $this->registrarNoFunil($request, $product->id);

        return redirect()->route('finalizacao.entrega');
    }

    /**
     * Funil da tela Comportamento (2026-11-20): quem pôs o produto no carrinho
     * ou clicou em COMPRAR AGORA. Só o visitante, o aparelho e o id do
     * produto (docs/privacidade-e-cookies.md); nunca quebra a compra.
     */
    private function registrarNoFunil(Request $request, int $produtoId): void
    {
        rescue(function () use ($request, $produtoId) {
            if (! TrackSiteVisit::dominioDaLoja($request) || TrackSiteVisit::naoENavegador($request)) {
                return;
            }
            CheckoutEvento::create([
                'visitor_id' => CheckoutEvento::visitante($request),
                'user_id' => $request->user()?->id,
                'evento' => CheckoutEvento::PRODUTO_CARRINHO,
                'campo' => (string) $produtoId,
                'dispositivo' => TrackSiteVisit::dispositivo((string) $request->userAgent()),
            ]);
        }, null, false);
    }

    /**
     * AddToCart na API de Conversões da Meta (pedido 2026-10-18), com o
     * event_id que o front mandou ao Pixel (meta_event_id), para a Meta
     * desduplicar. Só com o OK de cookies; na fila, sem atrasar a resposta.
     */
    private function addToCartNaMeta(Request $request, Product $product, int $quantidade): void
    {
        ConversoesMeta::semQuebrar(function () use ($request, $product, $quantidade) {
            $conversoes = app(ConversoesMeta::class);
            if (! $conversoes->consentiu($request)) {
                return;
            }

            $eventId = ConversoesMeta::eventIdValido($request->input('meta_event_id')) ?? (string) Str::uuid();
            EnviarEventoMetaJob::dispatch($conversoes->eventoAddToCart($request, $product, $quantidade, $eventId));
        });
    }
}
