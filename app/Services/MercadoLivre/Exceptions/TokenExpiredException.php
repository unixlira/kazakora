<?php

namespace App\Services\MercadoLivre\Exceptions;

class TokenExpiredException extends MercadoLivreException
{
    public static function make(): self
    {
        return new self('O token de acesso do Mercado Livre expirou ou é inválido.', 401);
    }
}
