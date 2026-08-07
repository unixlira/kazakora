<?php

namespace App\Services\Amazon\Exceptions;

use Exception;

/**
 * Mesmo padrão de ShopeeException/MercadoLivreException — $context carrega o
 * corpo cru da resposta (nunca exposto ao usuário final, só pra log/debug).
 */
class AmazonException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, int $statusCode = 0, public readonly array $context = [])
    {
        parent::__construct($message, $statusCode);
    }
}
