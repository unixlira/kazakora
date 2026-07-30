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
     * Suggests a Mercado Livre category (+ any brand/line attributes ML can
     * infer) for a free-text product title. `sites/MLB/category_predictor`
     * (the endpoint this used to call) was discontinued by Mercado Livre —
     * confirmed dead in production (returns their generic "resource not
     * found" message) — so this uses their current replacement,
     * `domain_discovery/search`, instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discoverCategory(string $title, string $siteId = 'MLB'): array
    {
        return $this->client->get("sites/{$siteId}/domain_discovery/search", ['q' => $title]);
    }
}
