<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Services\MercadoLivre\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);
    }

    public function test_get_order_maps_the_response_into_an_order_dto(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/orders/987654' => Http::response([
                'id' => 987654,
                'status' => 'paid',
                'total_amount' => 199.9,
                'currency_id' => 'BRL',
                'date_created' => '2026-07-22T10:00:00.000-04:00',
                'buyer' => ['id' => 1, 'nickname' => 'COMPRADOR'],
                'order_items' => [['item' => ['id' => 'MLB1'], 'quantity' => 1]],
                'shipping' => ['id' => 555],
            ]),
        ]);

        $order = app(OrderService::class)->getOrder('987654');

        $this->assertSame(987654, $order->id);
        $this->assertSame('paid', $order->status);
        $this->assertSame(199.9, $order->total_amount);
        $this->assertCount(1, $order->order_items);
    }

    public function test_update_order_status_sends_a_put_request(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/orders/987654' => Http::response(['id' => 987654, 'status' => 'cancelled']),
        ]);

        app(OrderService::class)->updateOrderStatus('987654', 'cancelled');

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['status'] === 'cancelled');
    }
}
