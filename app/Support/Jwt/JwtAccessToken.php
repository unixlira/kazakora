<?php

namespace App\Support\Jwt;

use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * Token "falso" (nunca gravado no banco) só pra satisfazer
 * Laravel\Sanctum\HasApiTokens::withAccessToken()/tokenCan() — é assim que
 * o middleware `abilities:`/`ability:` já existente (Sanctum) consegue
 * checar as abilities de um parceiro autenticado via JWT sem duplicar
 * essa lógica. Mesma semântica de PersonalAccessToken::can() (suporta '*'
 * coringa), só que as abilities vêm do payload do JWT em vez de uma linha
 * do banco.
 */
class JwtAccessToken implements HasAbilities
{
    // Público (não private) de propósito: Api\V1\MeController lê
    // ->abilities diretamente (mesmo acesso que já fazia em
    // PersonalAccessToken::$abilities do Sanctum) — precisa funcionar
    // igual pros dois tipos de token sem MeController saber qual é qual.
    public function __construct(public readonly array $abilities) {}

    public function can($ability)
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function cant($ability)
    {
        return ! $this->can($ability);
    }
}
