<?php

namespace App\Modules\Infoprodutos\Http\Middleware;

use App\Modules\Infoprodutos\Support\OrigemInfoproduto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS da coleta dos infoprodutos: só os domínios medidos. A rota fica fora
 * de /api/* de propósito (lá o CORS padrão libera qualquer origem).
 */
class CorsInfoprodutos
{
    public function handle(Request $request, Closure $next): Response
    {
        $origem = rtrim((string) $request->headers->get('Origin'), '/');
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($origem, PHP_URL_HOST)));
        $permitida = $origem !== '' && str_starts_with($origem, 'https://') && in_array($host, OrigemInfoproduto::dominios(), true);

        $resposta = $request->isMethod('OPTIONS') ? response('', $permitida ? 204 : 403) : $next($request);

        $resposta->headers->set('Vary', 'Origin', false);
        if ($permitida) {
            $resposta->headers->set('Access-Control-Allow-Origin', $origem);
            $resposta->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $resposta->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            $resposta->headers->set('Access-Control-Max-Age', '600');
        }
        $resposta->headers->set('Cache-Control', 'no-store');

        return $resposta;
    }
}
