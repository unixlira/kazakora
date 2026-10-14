<?php

namespace App\Http\Controllers\Api;

use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use App\Modules\BeautyKora\Services\CheckoutBeautyKora;
use App\Modules\Checkout\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Checkout do site Beauty Kora pelo Mercado Pago da KazaKora (pedido do
 * Lira 2026-10-14, só homolog), no lugar do checkout da Hostinger. Rotas e
 * regras em CheckoutOutroSiteController.
 */
class BeautyKoraCheckoutController extends CheckoutOutroSiteController
{
    public function __construct(CheckoutBeautyKora $checkout)
    {
        parent::__construct($checkout);
    }

    /**
     * Cliente logado no site (Bearer do Beauty Kora, opcional): um registro
     * por produto em "Meus pedidos", ligado ao pedido da KazaKora, de onde
     * vem a situação (BeautyKoraPedido::paraSite).
     */
    protected function depoisDoPedido(Request $request, Order $order, array $dados): void
    {
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());
        $cliente = $token?->tokenable;

        if (! $cliente instanceof BeautyKoraCliente || $token->expires_at?->isPast()) {
            return;
        }

        $lote = (string) Str::uuid();
        $endereco = collect($dados['endereco'])->only(['zip', 'street', 'number', 'complement', 'neighborhood', 'city', 'state'])->all();

        foreach ($order->items()->with('product.images', 'product.parent.images')->get() as $item) {
            BeautyKoraPedido::query()->create([
                'cliente_id' => $cliente->id,
                'order_id' => $order->id,
                'produto_id' => 'kz-'.($item->product?->parent_product_id ?? $item->product_id),
                'variante_id' => 'kzv-'.$item->product_id,
                'produto_nome' => $item->product_name,
                'produto_imagem' => $item->product?->images->first()?->url ?? $item->product?->parent?->images->first()?->url,
                'quantidade' => $item->quantity,
                'valor_pago_centavos' => (int) round((float) $item->subtotal * 100),
                'status' => 'pending',
                'endereco' => $endereco,
                'lote' => $lote,
                'email_checkout' => mb_strtolower($dados['cliente']['email']),
            ]);
        }
    }
}
