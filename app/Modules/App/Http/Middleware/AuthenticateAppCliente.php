<?php

namespace App\Modules\App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cliente logado no app nativo da KazaKora (2026-10-18): Bearer do Sanctum
 * de um User da KazaKora, criado pelo login do app (ability "app"). Não usa
 * auth:sanctum de propósito, como o AuthenticateBeautyKoraCliente: aquele
 * guard aceita token de qualquer dono (ApiPartner, cliente do Beauty Kora).
 *
 * AppCliente::doToken() também serve às rotas públicas que ficam melhores
 * com login (resumo do checkout: cupom de uso por pessoa).
 */
class AuthenticateAppCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        [$user, $token] = self::doToken($request) ?? [null, null];

        if (! $user) {
            return response()->json(['message' => 'Faça login para continuar.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $user->withAccessToken($token));

        return $next($request);
    }

    /** @return array{0: User, 1: PersonalAccessToken}|null */
    public static function doToken(Request $request): ?array
    {
        $bearer = (string) $request->bearerToken();
        $token = $bearer !== '' ? PersonalAccessToken::findToken($bearer) : null;
        $user = $token?->tokenable;

        if (! $user instanceof User || $user->trashed() || ! $token->can('app') || $token->expires_at?->isPast()) {
            return null;
        }

        return [$user, $token];
    }
}
