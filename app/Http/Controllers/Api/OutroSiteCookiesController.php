<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Modules\Analytics\Models\SiteVisit;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * POST /api/{site}/cookies (pedido do Lira 2026-11-18): o pop-up de cookies
 * da FerraKora (e da Beauty Kora, se ganhar um) grava aqui a escolha da
 * pessoa, com a mesma prova da loja (versão do aviso, data/hora, IP,
 * navegador) em consentimentos_cookies:
 *
 *   escolha=aceito  → "Aceito" (Pixel da Meta e API de Conversões liberados)
 *   escolha=basico  → "Somente o básico" (recusa: nada de terceiros)
 *   visitante=<uuid> (opcional): código guardado no navegador do site, para
 *                     ligar as escolhas do mesmo navegador.
 *
 * O site é de outro domínio: quem lembra a escolha é o próprio site
 * (localStorage); aqui fica só a prova. Só aceita os domínios em
 * services.{site}.origens (Origin, ou Referer), como a rota de visita.
 * Responde 204 sem corpo.
 */
class OutroSiteCookiesController extends Controller
{
    public function registrar(Request $request, string $site): Response
    {
        abort_unless(array_key_exists($site, SiteVisit::OUTROS_SITES) && in_array($site, ConsentimentoCookie::ORIGENS, true), 404);

        if (! $this->origemPermitida($request, $site)) {
            return response()->noContent(403);
        }

        $escolha = $request->input('escolha');
        if (! is_string($escolha) || ! in_array($escolha, ConsentimentoCookie::ESCOLHAS, true)) {
            return response()->noContent(422);
        }

        $visitante = $request->input('visitante');
        $visitante = is_string($visitante) && Str::isUuid($visitante) ? strtolower($visitante) : null;

        $escolha === ConsentimentoCookie::ESCOLHA_ACEITO
            ? Cookies::registrarAceite($request, $site, null, $visitante)
            : Cookies::registrarRecusa($request, $site, $visitante);

        return response()->noContent();
    }

    private function origemPermitida(Request $request, string $site): bool
    {
        $url = (string) ($request->headers->get('origin') ?: $request->headers->get('referer'));
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $dominios = array_values(array_filter(array_map(
            fn ($d) => preg_replace('/^www\./', '', strtolower(trim($d))),
            explode(',', (string) config("services.{$site}.origens")),
        )));

        return $host !== '' && in_array($host, $dominios, true);
    }
}
