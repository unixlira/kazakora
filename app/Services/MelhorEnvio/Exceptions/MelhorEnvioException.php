<?php

namespace App\Services\MelhorEnvio\Exceptions;

use RuntimeException;

class MelhorEnvioException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 0)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
