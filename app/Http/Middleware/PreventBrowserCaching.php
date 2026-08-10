<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedido explícito 2026-08-09: reabrir o navegador com várias abas restauradas
 * mostrava JSON cru em vez da tela renderizada. Causa real — sem essa
 * garantia, uma resposta do Inertia servida via XHR (navegação dentro do
 * app, manda header X-Inertia) podia ficar em cache do navegador na mesma
 * URL; ao restaurar a aba o navegador faz uma navegação de verdade (sem
 * X-Inertia) pra essa mesma URL e, sem "Vary: X-Inertia" nem "no-store", o
 * navegador tinha liberdade de reusar o JSON já cacheado em vez de pedir a
 * página de novo — daí o JSON aparecendo cru na tela.
 *
 * `no-store` sozinho já resolve (o navegador nunca guarda cópia nenhuma pra
 * reusar depois); Vary fica de bônus pra qualquer cache intermediário
 * (proxy/CDN) que respeite Vary mas ainda tente cachear por padrão.
 */
class PreventBrowserCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Vary', 'X-Inertia');
        }

        return $response;
    }
}
