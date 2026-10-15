<?php

namespace App\Http\Middleware;

use App\Modules\FerraKora\Models\FerraKoraCliente;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente logado no site FerraKora (pedido 2026-10-14): Bearer do Sanctum que
 * pertence a um FerraKoraCliente. Mesma regra do AuthenticateBeautyKoraCliente:
 * token de outro dono (ApiPartner, cliente do Beauty Kora) não entra.
 */
class AuthenticateFerraKoraCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());
        $cliente = $token?->tokenable;

        if (! $cliente instanceof FerraKoraCliente || $token->expires_at?->isPast()) {
            return response()->json(['message' => 'Faça login para continuar.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('ferrakora_token', $token);
        $request->setUserResolver(fn () => $cliente->withAccessToken($token));

        return $next($request);
    }
}
