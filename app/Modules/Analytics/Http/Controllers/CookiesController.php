<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OK da faixa de cookies (pedido 2026-10-10). A faixa saiu em 2026-10-24,
 * voltou em 2026-10-25 e saiu de novo em 2026-10-26: o aceite vem do
 * "Finalizar pedido" (checkout). A rota fica para o OK vindo de outro lugar.
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

    /** Site de onde veio o OK; qualquer valor fora da lista vale KazaKora. */
    private function origem(Request $request): string
    {
        $origem = strtolower((string) $request->input('origem'));

        return in_array($origem, ConsentimentoCookie::ORIGENS, true)
            ? $origem
            : ConsentimentoCookie::ORIGEM_KAZAKORA;
    }
}
