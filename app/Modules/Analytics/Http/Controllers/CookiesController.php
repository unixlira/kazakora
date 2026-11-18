<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Botões do pop-up de cookies (AvisoCookies.vue, pedido do Lira 2026-11-18):
 * "Aceito" (aceitar) e "Somente o básico" (recusar). Os dois gravam a prova
 * da escolha e lembram dela, para o pop-up não voltar a cada página.
 * Histórico: faixa de 2026-10-10, fora em 2026-10-24, de volta em 2026-10-25
 * e fora de novo em 2026-10-26 (aceite só no "Finalizar pedido", que continua).
 */
class CookiesController extends Controller
{
    public function aceitar(Request $request): RedirectResponse
    {
        $jaAceitou = Cookies::aceitou($request);
        $visitante = Cookies::registrarAceite($request, $this->origem($request));

        if (! $jaAceitou) {
            TrackSiteVisit::registrarOk($request, $visitante);
        }

        return back(303);
    }

    /** "Somente o básico": sem Pixel, sem API de Conversões, só o necessário. */
    public function recusar(Request $request): RedirectResponse
    {
        Cookies::registrarRecusa($request);

        return back(303);
    }

    /** Site de onde veio o OK; qualquer valor fora da lista vale KazaKora. */
    private function origem(Request $request): string
    {
        $origem = strtolower((string) $request->input('origem'));

        return in_array($origem, ConsentimentoCookie::ORIGENS, true)
            ? $origem
            : ConsentimentoCookie::ORIGEM_KAZAKORA;
    }
}
