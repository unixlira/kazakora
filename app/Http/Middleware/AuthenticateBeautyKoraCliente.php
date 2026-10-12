<?php

namespace App\Http\Middleware;

use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente logado no site Beauty Kora (pedido 2026-10-12): Bearer do Sanctum
 * que pertence a um BeautyKoraCliente. Não usa auth:sanctum de propósito:
 * aquele guard aceita token de qualquer dono (ApiPartner, por exemplo), e
 * aqui só vale token de cliente do Beauty Kora.
 */
class AuthenticateBeautyKoraCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());
        $cliente = $token?->tokenable;

        if (! $cliente instanceof BeautyKoraCliente || $token->expires_at?->isPast()) {
            return response()->json(['message' => 'Faça login para continuar.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('beautykora_token', $token);
        $request->setUserResolver(fn () => $cliente->withAccessToken($token));

        return $next($request);
    }
}
