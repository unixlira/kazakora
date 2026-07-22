<?php

namespace App\Services\MercadoLivre\Exceptions;

class RateLimitException extends MercadoLivreException
{
    public static function make(?int $retryAfterSeconds = null): self
    {
        return new self(
            'Limite de requisições da API do Mercado Livre atingido (HTTP 429).',
            429,
            ['retry_after_seconds' => $retryAfterSeconds],
        );
    }
}
