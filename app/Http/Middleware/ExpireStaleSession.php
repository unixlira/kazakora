<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedido explícito 2026-08-09: encerrar a sessão de verdade depois de 8h,
 * mesmo com atividade constante — SESSION_LIFETIME (config/session.php) por
 * si só é uma janela DESLIZANTE (renova a cada request), nunca expira
 * sozinha enquanto tiver uso; isso aqui é um teto absoluto contado a partir
 * do login, complementar ao [[PreventBrowserCaching]] (a causa raiz do bug
 * de "abre com JSON cru").
 *
 * `login_at` é gravado no listener do evento Login (AppServiceProvider) —
 * cobre login normal, "lembrar-me" e o auto-login do checkout como
 * convidado, todos disparam esse mesmo evento nativo do Laravel. Esse
 * middleware também inicializa `login_at` sozinho na primeira vez que vê
 * uma sessão autenticada sem essa marca (sessão de antes do recurso
 * existir) — evita derrubar todo mundo de uma vez no deploy.
 */
class ExpireStaleSession
{
    private const MAX_SESSION_HOURS = 8;

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $loginAt = $request->session()->get('login_at');

            // Sessão de antes desse recurso existir (ou autenticada fora do
            // fluxo normal de login, ex.: actingAs() nos testes) não tem
            // login_at ainda — em vez de forçar logout às cegas, começa a
            // contar a partir de agora. O teto de 8h passa a valer de fato
            // a partir da próxima vez que a pessoa logar de verdade.
            if ($loginAt === null) {
                $request->session()->put('login_at', now()->timestamp);
            } elseif (now()->timestamp - $loginAt >= self::MAX_SESSION_HOURS * 3600) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return Inertia::location(route('entrar'));
            }
        }

        return $next($request);
    }
}
