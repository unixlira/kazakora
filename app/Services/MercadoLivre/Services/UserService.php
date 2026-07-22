<?php

namespace App\Services\MercadoLivre\Services;

use App\Services\MercadoLivre\MercadoLivreClient;

class UserService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function getMyInfo(): array
    {
        return $this->client->get('users/me');
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserInfo(int $userId): array
    {
        return $this->client->get("users/{$userId}");
    }
}
