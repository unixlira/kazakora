<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O agente local de impressão não é um usuário do sistema — é um programa
 * rodando num PC/Mac fisicamente na loja, sem sessão/CSRF. Autentica por um
 * token fixo (Bearer), configurado uma vez no agente e no servidor
 * (PRINT_AGENT_TOKEN). Suficiente pra esse caso de uso único (um agente por
 * loja) — não precisa da máquina completa de tokens por usuário do Sanctum.
 */
class AuthenticatePrintAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.print_agent.token');

        abort_if(! $token, 503, 'Agente de impressão não configurado no servidor.');
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
