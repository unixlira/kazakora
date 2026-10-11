<?php

namespace App\Services\MercadoLivre\DTOs;

use Spatie\LaravelData\Data;

class TokenDTO extends Data
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $access_token,
        public string $refresh_token,
        public int $expires_in,
        public int $user_id,
        public array $scopes = [],
    ) {}
}
