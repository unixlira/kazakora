<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoLivreClaimsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrderWithItem(Product $product, int $quantity = 2): Order
    {
        $order = Order::create([
            'origin' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => 'ML-1',
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 50,
            'quantity' => $quantity,
            'subtotal' => 100,
        ]);

        return $order;
    }

    public function test_index_lists_claims_with_friendly_labels(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['stock' => 5]);
        $order = $this->makeOrderWithItem($product);

        MarketplaceClaim::create([
            'order_id' => $order->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_claim_id' => '5551229055',
            'type' => 'mediations',
            'stage' => 'claim',
            'status' => 'opened',
            'reason_id' => 'PDD9202',
            'claim_created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/devolucoes');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('claims.0.externalClaimId', '5551229055')
            ->where('claims.0.typeLabel', 'Mediação')
            ->where('claims.0.stageLabel', 'Reclamação')
            ->where('claims.0.statusLabel', 'Aberta')
            ->where('claims.0.canRevertStock', true));
    }

    public function test_revert_stock_restores_quantity_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['stock' => 5]);
        $order = $this->makeOrderWithItem($product, quantity: 2);
        $claim = MarketplaceClaim::create([
            'order_id' => $order->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_claim_id' => '1',
            'status' => 'closed',
        ]);

        $this->actingAs($admin)
            ->post("/admin/integracoes/mercado-livre/devolucoes/{$claim->id}/reverter-estoque")
            ->assertRedirect();

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertNotNull($order->fresh()->stock_restored_at);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'quantity' => 2,
            'type' => StockMovement::TYPE_RETURN,
        ]);

        // Segunda tentativa não duplica a devolução de estoque.
        $this->actingAs($admin)->post("/admin/integracoes/mercado-livre/devolucoes/{$claim->id}/reverter-estoque");
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_revert_stock_is_rejected_when_the_claim_has_no_local_order(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $claim = MarketplaceClaim::create([
            'order_id' => null,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_claim_id' => '2',
            'status' => 'opened',
        ]);

        $this->actingAs($admin)
            ->post("/admin/integracoes/mercado-livre/devolucoes/{$claim->id}/reverter-estoque")
            ->assertRedirect();
    }
}
