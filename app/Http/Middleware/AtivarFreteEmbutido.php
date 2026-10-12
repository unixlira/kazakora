<?php

namespace App\Http\Middleware;

use App\Modules\Checkout\Support\FreteEmbutido;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liga o frete embutido no preço (FreteEmbutido) só nas telas da loja: o
 * admin, a API e os webhooks continuam vendo o preço de cadastro.
 */
class AtivarFreteEmbutido
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('admin', 'admin/*', 'api/*', 'webhooks/*', 'horizon', 'horizon/*')) {
            app(FreteEmbutido::class)->ligar();
        }

        return $next($request);
    }
}
