<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Services\MercadoLivre\DTOs\ProductDTO;
use App\Services\MercadoLivre\Exceptions\RateLimitException;
use App\Services\MercadoLivre\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedValidToken(): MercadoLivreToken
    {
        return MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);
    }

    public function test_create_item_posts_the_dto_payload_and_returns_the_response(): void
    {
        $this->seedValidToken();

        Http::fake([
            'https://api.mercadolibre.com/items' => Http::response([
                'id' => 'MLB123456',
                'title' => 'Vaso Decorativo',
            ], 201),
        ]);

        $dto = new ProductDTO(
            title: 'Vaso Decorativo',
            category_id: 'MLB12345',
            price: 99.9,
            available_quantity: 10,
        );

        $result = app(ProductService::class)->createItem($dto);

        $this->assertSame('MLB123456', $result['id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadolibre.com/items'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer valid-access-token')
                && $request['title'] === 'Vaso Decorativo';
        });
    }

    public function test_update_stock_sends_available_quantity(): void
    {
        $this->seedValidToken();

        Http::fake([
            'https://api.mercadolibre.com/items/MLB123456' => Http::response(['id' => 'MLB123456'], 200),
        ]);

        app(ProductService::class)->updateStock('MLB123456', 42);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request['available_quantity'] === 42);
    }

    public function test_rate_limit_response_throws_rate_limit_exception(): void
    {
        $this->seedValidToken();

        Http::fake([
            'https://api.mercadolibre.com/items/MLB123456' => Http::response(['message' => 'too many requests'], 429),
        ]);

        $this->expectException(RateLimitException::class);

        app(ProductService::class)->updateStock('MLB123456', 1);
    }
}
