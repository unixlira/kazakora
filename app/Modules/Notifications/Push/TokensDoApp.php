<?php

namespace App\Modules\Notifications\Push;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token dos apps de vendas (2026-11-19: app.kazakora.com e app.ferrakora.com
 * são sites próprios, sem a sessão do painel). Um token do Sanctum por
 * aparelho e por app, só de admin, com validade de DIAS dias; sair apaga.
 *
 * O User não usa HasApiTokens de propósito: assim o guard auth:sanctum (API
 * de parceiros) continua recusando token de usuário, e este token só vale
 * nas rotas do app (AutenticarAppVendas), e só no app para o qual saiu.
 */
class TokensDoApp
{
    public const DIAS = 60;

    public static function habilidade(string $app): string
    {
        return 'apps-vendas:'.$app;
    }

    /** @return array{token: string, expira_em: string} */
    public static function emitir(User $user, string $app, string $aparelho = ''): array
    {
        $texto = Str::random(48);
        $token = PersonalAccessToken::query()->forceCreate([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'name' => Str::limit('app-vendas:'.$app.($aparelho !== '' ? ':'.$aparelho : ''), 250, ''),
            'token' => hash('sha256', $texto),
            'abilities' => [self::habilidade($app)],
            'expires_at' => now()->addDays(self::DIAS),
        ]);

        return ['token' => $token->getKey().'|'.$texto, 'expira_em' => $token->expires_at->toIso8601String()];
    }

    /** Token válido deste app, de um admin; null em qualquer outro caso. */
    public static function validar(?string $bruto, string $app): ?PersonalAccessToken
    {
        $token = $bruto ? PersonalAccessToken::findToken($bruto) : null;
        $user = $token?->tokenable;

        if (! $user instanceof User || $user->role !== User::ROLE_ADMIN
            || $token->expires_at === null || $token->expires_at->isPast()
            || ! in_array(self::habilidade($app), (array) $token->abilities, true)) {
            return null;
        }

        return $token;
    }
}
