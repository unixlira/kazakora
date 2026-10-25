<?php

namespace App\Http\Middleware;

use App\Modules\Analytics\Support\GeoDoVisitante;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Localização aproximada do visitante da loja pelo IP, uma vez por sessão
 * (pedido 2026-10-24, GeoDoVisitante). Antes da resposta, para a mesma
 * página já poder ler o lugar da sessão. Mesmo filtro das visitas: só o
 * domínio da loja, sem admin, sem arquivos e sem robô. Erro aqui nunca
 * derruba a página.
 */
class RegistrarGeoDoVisitante
{
    public function __construct(private readonly GeoDoVisitante $geo) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->deveRegistrar($request)) {
            rescue(fn () => $this->geo->registrarDaLoja($request), null, false);
        }

        return $next($request);
    }

    private function deveRegistrar(Request $request): bool
    {
        if (! $request->isMethod('get') || ! $request->hasSession()) {
            return false;
        }

        if ($request->is('admin', 'admin/*', 'api/*', 'webhooks/*', 'horizon', 'horizon/*', 'build/*', 'storage/*')) {
            return false;
        }

        return TrackSiteVisit::dominioDaLoja($request) && ! TrackSiteVisit::naoENavegador($request);
    }
}
