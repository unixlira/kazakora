<?php

namespace App\Http\Middleware;

use App\Modules\FerraKora\Models\FerraKoraSessao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente logado no site FerraKora (pedido 2026-10-14): Bearer de uma
 * FerraKoraSessao válida, cujo dono é a conta da KazaKora (User). Token do
 * Sanctum (ApiPartner, cliente do Beauty Kora) não entra.
 */
class AuthenticateFerraKoraCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessao = FerraKoraSessao::doToken($request->bearerToken());

        if (! $sessao) {
            return response()->json(['message' => 'Faça login para continuar.'], 401);
        }

        $sessao->marcarUso();
        $request->attributes->set('ferrakora_sessao', $sessao);
        $request->setUserResolver(fn () => $sessao->user);

        return $next($request);
    }
}
