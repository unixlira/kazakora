<?php

namespace App\Support\Jwt;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Encode/decode do JWT de login self-service de parceiro de API — pedido
 * explícito 2026-08-22, alternativa ao token estático emitido só pelo
 * admin (ApiPartnerController::issueToken()). HS256 com segredo dedicado
 * (config('services.api_partner_jwt.secret'), cai pra APP_KEY se não
 * setado — nunca reaproveita a chave de sessão/cookie da aplicação de
 * propósito, um segredo comprometido aqui não deveria comprometer sessões
 * web). Claims mínimos: sub (id do parceiro), abilities (congeladas no
 * login, mesmo comportamento do token estático — trocar abilities do
 * parceiro depois não muda um JWT já emitido, só o próximo login), exp.
 */
class ApiPartnerJwt
{
    public const TTL_SECONDS = 3600;

    public static function issue(int $partnerId, array $abilities): string
    {
        $now = time();

        return JWT::encode([
            'iss' => config('app.url'),
            'sub' => $partnerId,
            'abilities' => $abilities,
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ], self::secret(), 'HS256');
    }

    /**
     * @return array{sub: int, abilities: array<int, string>}|null null em
     * qualquer falha (assinatura inválida, expirado, malformado) — o
     * chamador trata tudo isso como "não autenticado", sem distinguir o
     * motivo pro cliente (não vaza detalhe de validação de token).
     */
    public static function decode(string $token): ?array
    {
        try {
            $payload = JWT::decode($token, new Key(self::secret(), 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        if (! isset($payload->sub, $payload->abilities) || ! is_array($payload->abilities)) {
            return null;
        }

        return [
            'sub' => (int) $payload->sub,
            'abilities' => $payload->abilities,
        ];
    }

    private static function secret(): string
    {
        return config('services.api_partner_jwt.secret') ?: config('app.key');
    }
}
