<?php

namespace Tests\Feature\MercadoLivre;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\MercadoLivre\Services\ClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $externalOrderId): Order
    {
        return Order::create([
            'origin' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => $externalOrderId,
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
    }

    public function test_it_creates_a_claim_linked_to_the_matching_order(): void
    {
        $order = $this->makeOrder('2000017767782324');

        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->once()->with('post-purchase/v1/claims/5551229055')->andReturn([
            'id' => 5551229055,
            'type' => 'mediations',
            'stage' => 'claim',
            'status' => 'opened',
            'resource' => 'order',
            'resource_id' => '2000017767782324',
            'reason_id' => 'PDD9202',
            'resolution' => null,
            'date_created' => '2026-08-05T13:52:58.000Z',
            'last_updated' => '2026-08-05T13:52:58.000Z',
        ]);

        (new ClaimService($client))->processWebhook([
            'topic' => 'post_purchase',
            'resource' => '/post-purchase/v1/claims/5551229055',
        ]);

        $this->assertDatabaseHas('marketplace_claims', [
            'external_claim_id' => '5551229055',
            'order_id' => $order->id,
            'type' => 'mediations',
            'stage' => 'claim',
            'status' => 'opened',
            'reason_id' => 'PDD9202',
        ]);
    }

    public function test_it_stores_the_claim_without_an_order_when_no_local_order_matches(): void
    {
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->once()->andReturn([
            'id' => 999,
            'resource' => 'order',
            'resource_id' => 'ORDER-NAO-EXISTE',
            'status' => 'opened',
        ]);

        (new ClaimService($client))->processWebhook(['resource' => '/post-purchase/v1/claims/999']);

        $this->assertDatabaseHas('marketplace_claims', [
            'external_claim_id' => '999',
            'order_id' => null,
        ]);
    }

    public function test_it_does_nothing_when_the_resource_is_unparseable(): void
    {
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldNotReceive('get');

        (new ClaimService($client))->processWebhook(['resource' => '/something/weird']);

        $this->assertDatabaseCount('marketplace_claims', 0);
    }

    public function test_it_logs_and_gives_up_gracefully_when_the_claim_api_call_fails(): void
    {
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->once()->andThrow(new RuntimeException('claim not found'));

        // Não deve lançar — falha de enriquecimento não pode derrubar o
        // worker nem marcar o webhook como "failed" pro Mercado Livre reenviar
        // indefinidamente.
        (new ClaimService($client))->processWebhook(['resource' => '/post-purchase/v1/claims/1']);

        $this->assertDatabaseCount('marketplace_claims', 0);
    }

    public function test_repeated_webhooks_for_the_same_claim_update_instead_of_duplicating(): void
    {
        $this->makeOrder('123');
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->twice()->andReturn(
            ['resource' => 'order', 'resource_id' => '123', 'status' => 'opened'],
            ['resource' => 'order', 'resource_id' => '123', 'status' => 'closed'],
        );

        $service = new ClaimService($client);
        $service->processWebhook(['resource' => '/post-purchase/v1/claims/42']);
        $service->processWebhook(['resource' => '/post-purchase/v1/claims/42']);

        $this->assertDatabaseCount('marketplace_claims', 1);
        $this->assertDatabaseHas('marketplace_claims', ['external_claim_id' => '42', 'status' => 'closed']);
    }
}
