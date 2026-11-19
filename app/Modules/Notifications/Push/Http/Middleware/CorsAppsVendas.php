<?php

namespace App\Modules\Notifications\Push\Http\Middleware;

use App\Modules\Notifications\Push\AppsDeVendas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS da API dos apps de vendas: só as origens dos dois apps
 * (app.kazakora.com e app.ferrakora.com). A rota fica fora de /api/* de
 * propósito: lá o CORS padrão do Laravel libera qualquer origem.
 */
class CorsAppsVendas
{
    public function handle(Request $request, Closure $next): Response
    {
        $origem = (string) $request->headers->get('Origin');
        $permitida = in_array(rtrim($origem, '/'), AppsDeVendas::origens(), true);

        $resposta = $request->isMethod('OPTIONS') ? response('', $permitida ? 204 : 403) : $next($request);

        $resposta->headers->set('Vary', 'Origin', false);
        if ($permitida) {
            $resposta->headers->set('Access-Control-Allow-Origin', $origem);
            $resposta->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $resposta->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
            $resposta->headers->set('Access-Control-Max-Age', '600');
        }
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }
}
