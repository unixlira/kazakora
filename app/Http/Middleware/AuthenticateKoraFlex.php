<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O KoraFlex é um app de celular, sem sessão e sem CSRF — autentica por
 * token fixo (Bearer), igual o agente de impressão.
 *
 * Token PRÓPRIO (KORAFLEX_TOKEN), e não o do agente, de propósito: o
 * celular vive fora do balcão, sai do prédio e pode ser perdido. Com token
 * separado, o que vaza dá acesso só ao que o KoraFlex faz (ver a lista do
 * dia e carimbar "pronto pra coleta"), nunca à dashboard inteira nem à
 * fila de impressão — e trocar o token do celular não derruba a
 * impressora.
 */
class AuthenticateKoraFlex
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.koraflex.token');

        abort_if(! $token, 503, 'KoraFlex não configurado no servidor.');
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
