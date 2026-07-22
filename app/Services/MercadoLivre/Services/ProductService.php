<?php

namespace App\Services\MercadoLivre\Services;

use App\Services\MercadoLivre\DTOs\ProductDTO;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;

class ProductService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function listItems(int $mlUserId): array
    {
        return $this->client->get("users/{$mlUserId}/items/search");
    }

    public function getItem(string $itemId): ProductDTO
    {
        $item = $this->client->get("items/{$itemId}");

        return new ProductDTO(
            title: $item['title'],
            category_id: $item['category_id'],
            price: (float) $item['price'],
            available_quantity: (int) $item['available_quantity'],
            buying_mode: $item['buying_mode'] ?? 'buy_it_now',
            listing_type_id: $item['listing_type_id'] ?? 'gold_special',
            condition: $item['condition'] ?? 'new',
            currency_id: $item['currency_id'] ?? 'BRL',
            description: $item['description']['plain_text'] ?? null,
            pictures: $item['pictures'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createItem(ProductDTO $dto): array
    {
        return $this->client->post('items', $dto->toArray());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateItem(string $itemId, array $data): array
    {
        return $this->client->put("items/{$itemId}", $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePrice(string $itemId, float $price): array
    {
        return $this->updateItem($itemId, ['price' => $price]);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStock(string $itemId, int $quantity): array
    {
        return $this->updateItem($itemId, ['available_quantity' => $quantity]);
    }

    /**
     * @return array<string, mixed>
     */
    public function pauseItem(string $itemId): array
    {
        return $this->updateItem($itemId, ['status' => 'paused']);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteItem(string $itemId): array
    {
        return $this->client->delete("items/{$itemId}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.items', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processPriceUpdate(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.prices', $payload);
    }
}
