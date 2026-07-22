<?php

namespace App\Services\MercadoLivre\Services;

use App\Services\MercadoLivre\MercadoLivreClient;

class CategoryService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(string $siteId = 'MLB'): array
    {
        return $this->client->get("sites/{$siteId}/categories");
    }

    /**
     * @return array<string, mixed>
     */
    public function getCategory(string $categoryId): array
    {
        return $this->client->get("categories/{$categoryId}");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function predictCategory(string $title): array
    {
        return $this->client->post('sites/MLB/category_predictor', ['title' => $title]);
    }
}
