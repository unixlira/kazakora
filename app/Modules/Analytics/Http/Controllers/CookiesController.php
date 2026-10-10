<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/** OK do aviso de cookies (pedido 2026-10-10). */
class CookiesController extends Controller
{
    public function aceitar(Request $request): RedirectResponse
    {
        $visitante = $request->cookie(Cookies::COOKIE_VISITANTE) ?: (string) Str::uuid();

        if (! Cookies::aceitou($request)) {
            ConsentimentoCookie::create([
                'visitante_id' => $visitante,
                'user_id' => $request->user()?->id,
                'versao' => Cookies::VERSAO,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'aceito_em' => now(),
            ]);
        }

        Cookie::queue(Cookies::COOKIE_OK, Cookies::VERSAO, Cookies::DURACAO_MINUTOS);
        Cookie::queue(Cookies::COOKIE_VISITANTE, $visitante, Cookies::DURACAO_MINUTOS);

        return back(303);
    }
}
