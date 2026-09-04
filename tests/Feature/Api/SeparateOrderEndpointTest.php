<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Modules\Marketplace\Support\SeparationGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Fase 3 (2026-09-04) — o clique de separar deixou de ser cego.
 *
 * O que importa provar aqui é a regra de segurança: pedido cancelado no
 * canal NÃO pode ser embalado, e o operador tem que receber o texto que diz
 * o que fazer com o produto que já está na mão dele.
 */
class SeparateOrderEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    private function makeOrder(string $origin, string $status = Order::STATUS_PAID): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'status' => $status,
            'origin' => $origin,
            'external_order_id' => '999888777',
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

    public function test_it_rejects_requests_without_a_valid_token(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar")->assertStatus(401);
    }

    /**
     * TikTok entra pela ponte do Bling e a etiqueta continua saindo no
     * painel do TikTok — o clique só embala, sem consultar canal nenhum.
     */
    public function test_tiktok_order_is_packed_without_any_channel_check(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldNotReceive('import');
        $this->app->instance(OrderImportService::class, $importer);

        $response = $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders());

        $response->assertOk()
            ->assertJson(['result' => 'ok', 'channel_checked' => false]);

        $this->assertNotNull($order->refresh()->packed_at);
    }

    /**
     * O caso que a Fase 3 existe pra cobrir: a venda caiu no marketplace
     * DEPOIS de já estar na fila. Não embala, e devolve a mensagem do modal.
     */
    public function test_order_cancelled_at_the_channel_is_not_packed_and_returns_the_modal_message(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        // A reconsulta no canal descobre o cancelamento — é o import real
        // que grava o status novo, então o mock reproduz esse efeito.
        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')
            ->once()
            ->andReturnUsing(function () use ($order) {
                $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();

                return $order;
            });
        $this->app->instance(OrderImportService::class, $importer);

        $response = $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders());

        $response->assertOk()
            ->assertJson([
                'result' => 'cancelled',
                'message' => SeparationGateService::CANCELLED_MESSAGE,
            ]);

        $this->assertNull($order->refresh()->packed_at, 'Pedido cancelado não pode ser marcado como embalado.');
    }

    /**
     * Falha-aberto: canal fora do ar não pode travar o galpão — embala, mas
     * avisa que ninguém conferiu.
     *
     * Queue::fake() aqui não é decoração: sem ele o dispatch do empurrão de
     * etiqueta roda INLINE (QUEUE_CONNECTION=sync no phpunit.xml) e bate
     * numa conta de canal não conectada. Produção usa fila database, então
     * o dispatch nunca executa dentro da requisição — o fake reproduz o
     * comportamento real, não o esconde.
     */
    public function test_channel_failure_still_packs_but_warns_the_operator(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_SHOPEE);

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->andThrow(new \RuntimeException('API fora'));
        $this->app->instance(OrderImportService::class, $importer);

        $response = $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders());

        $response->assertOk()->assertJson(['result' => 'ok', 'channel_checked' => false]);
        $this->assertNotNull($order->refresh()->packed_at);
        $this->assertStringContainsString('Não deu pra confirmar', $response->json('message'));
    }

    /**
     * Pedido ativo de canal com etiqueta de verdade: além de embalar, o
     * fluxo de etiqueta é acionado — "se estiver ativo, aí gera/busca a
     * etiqueta" do briefing.
     */
    public function test_active_marketplace_order_is_packed_and_kicks_the_label_flow(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->andReturn($order);
        $this->app->instance(OrderImportService::class, $importer);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => 'ok', 'channel_checked' => true]);

        $this->assertNotNull($order->refresh()->packed_at);
        Queue::assertPushed(ConfirmChannelShippingJob::class);
    }

    public function test_an_order_that_is_not_paid_is_refused(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP, Order::STATUS_AWAITING_PAYMENT);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertStatus(409)
            ->assertJson(['result' => 'blocked']);
    }
}
