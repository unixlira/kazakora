<?php

namespace App\Modules\Marketplace\Exceptions;

use RuntimeException;

class MarketplaceNotConfiguredException extends RuntimeException
{
    public static function forChannel(string $channel): self
    {
        return new self("A conta do canal \"{$channel}\" ainda não foi conectada. Configure as credenciais de API antes de publicar produtos.");
    }
}
