<?php

namespace App\Services\Stripe;

use RuntimeException;

class StripeNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Stripe não está configurado. Peça para adicionarem STRIPE_KEY e STRIPE_SECRET no .env.');
    }
}
