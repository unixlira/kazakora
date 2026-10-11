<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Modules\Marketplace\Drivers\MercadoLivreDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedido do usuário 2026-10-06 (margem "oficial e fidedigna"): a taxa do
 * Mercado Livre passa a somar o frete pago pelo vendedor, não só a
 * comissão. Números do pedido real #2700.
 */
class FeeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 3283064948,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '3283064948',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'connected_at' => now(),
        ]);
    }

    private function fakeOrder(?string $packId = null): array
    {
        return [
            'id' => 2000013, 'status' => 'paid', 'total_amount' => 78.99, 'currency_id' => 'BRL',
            'date_created' => '2026-10-01T01:14:50.000-03:00',
            'order_items' => [['item' => ['id' => 'MLB1'], 'quantity' => 1, 'unit_price' => 78.99, 'sale_fee' => 13.43]],
            'shipping' => ['id' => 555],
            'pack_id' => $packId,
        ];
    }

    public function test_fee_includes_the_shipping_the_seller_pays(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/orders/2000013' => Http::response($this->fakeOrder()),
            'https://api.mercadolibre.com/shipments/555/costs' => Http::response([
                'senders' => [['user_id' => 3283064948, 'cost' => 14.45, 'compensation' => 0]],
            ]),
        ]);

        $quebra = app(MercadoLivreDriver::class)->feeBreakdownFor('2000013');

        $this->assertSame(13.43, $quebra['commission_fee']);
        $this->assertSame(14.45, $quebra['shipping_fee']);
        $this->assertSame(27.88, $quebra['fee_amount']);
        $this->assertSame(51.11, $quebra['payout_amount']);
    }

    public function test_pack_shipping_is_split_between_its_orders(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/orders/2000013' => Http::response($this->fakeOrder('2000009')),
            'https://api.mercadolibre.com/shipments/555/costs' => Http::response([
                'senders' => [['user_id' => 3283064948, 'cost' => 20.00, 'compensation' => 0]],
            ]),
            'https://api.mercadolibre.com/packs/2000009' => Http::response(['orders' => [['id' => 1], ['id' => 2]]]),
        ]);

        $quebra = app(MercadoLivreDriver::class)->feeBreakdownFor('2000013');

        $this->assertSame(10.0, $quebra['shipping_fee']);
        $this->assertSame(23.43, $quebra['fee_amount']);
    }

    /** Falha na consulta do frete: fica a comissão, frete nulo (não zero). */
    public function test_shipping_lookup_failure_keeps_only_the_commission(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/orders/2000013' => Http::response($this->fakeOrder()),
            'https://api.mercadolibre.com/shipments/555/costs' => Http::response(['message' => 'boom'], 500),
        ]);

        $quebra = app(MercadoLivreDriver::class)->feeBreakdownFor('2000013');

        $this->assertSame(13.43, $quebra['fee_amount']);
        $this->assertNull($quebra['shipping_fee']);
        $this->assertNull($quebra['payout_amount']);
    }
}
