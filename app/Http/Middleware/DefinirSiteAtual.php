<?php

namespace App\Http\Middleware;

use App\Support\SiteAtual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site da requisição na API (Ofertas Relâmpago por empresa, pedido
 * 2026-10-14): /api/ferrakora/* = FerraKora, /api/beautykora/* = Beauty Kora.
 * O resto da API (marketplaces, webhooks, parceiros) fica sem site, sem
 * relâmpago.
 */
class DefinirSiteAtual
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/ferrakora', 'api/ferrakora/*')) {
            app(SiteAtual::class)->definir(SiteAtual::FERRAKORA);
        } elseif ($request->is('api/beautykora', 'api/beautykora/*')) {
            app(SiteAtual::class)->definir(SiteAtual::BEAUTYKORA);
        }

        return $next($request);
    }
}
