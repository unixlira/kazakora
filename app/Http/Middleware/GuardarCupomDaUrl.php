<?php

namespace App\Http\Middleware;

use App\Modules\Checkout\Models\Coupon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Link de cupom (pedido 2026-10-10): o e-mail do disparo leva para
 * "/?cupom=CODIGO". Qualquer página com ?cupom= guarda o código no rascunho
 * do checkout — o desconto já aparece aplicado quando o cliente finalizar
 * (as regras são conferidas lá, com o carrinho na mão).
 */
class GuardarCupomDaUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->filled('cupom') && ! $request->is('admin/*')) {
            $coupon = Coupon::buscar((string) $request->query('cupom'));

            if ($coupon?->is_active) {
                $draft = $request->session()->get('checkout_draft', []);
                $draft['coupon_code'] = $coupon->code;
                $request->session()->put('checkout_draft', $draft);
                $request->session()->flash('success', "Cupom {$coupon->code} guardado! O desconto entra quando você finalizar a compra.");
            }
        }

        return $next($request);
    }
}
