<?php

namespace App\Services\MercadoPago\Exceptions;

use RuntimeException;

class MercadoPagoException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 0, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
