<?php

namespace App\Services\MercadoLivre\Exceptions;

use RuntimeException;

class MercadoLivreException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        int $code = 0,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $code);
    }
}
