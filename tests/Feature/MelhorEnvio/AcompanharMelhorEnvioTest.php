<?php

namespace Tests\Feature\MelhorEnvio;

use App\Models\MelhorEnvioToken;
use App\Models\User;
use App\Modules\Checkout\Jobs\EnviarEmailPedidoEnviadoJob;
use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Rastreio do Melhor Envio refletido no pedido do site (2026-11-09, #3978). */
class AcompanharMelhorEnvioTest extends TestCase
{
    use RefreshDatabase;

    private const ME_ID = 'a32f4245-1b24-4bde-8380-8df853daad3d';

    private array $situacao = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.melhorenvio.api_base_url' => 'https://me.test/api/v2']);
        MelhorEnvioToken::create([
            'account_label' => 'KazaKora ME', 'access_token' => 'acesso', 'refresh_token' => 'r1',
            'token_expires_at' => now()->addDays(20), 'scopes' => [],
        ]);
        Http::fake(['me.test/*' => fn () => Http::response([self::ME_ID => ['id' => self::ME_ID] + $this->situacao])]);
    }

    private function pedido(): Order
    {
        $cliente = User::factory()->create();
        $order = Order::create([
            'user_id' => $cliente->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'external_order_id' => '261109ARV', 'shipping_name' => 'José Marques', 'shipping_phone' => '73991319795',
            'shipping_zip' => '45605-482', 'shipping_street' => 'Rua X', 'shipping_number' => '203',
            'shipping_neighborhood' => 'Jardim Vitória', 'shipping_city' => 'Itabuna', 'shipping_state' => 'BA',
            'shipping_cost' => 0, 'subtotal' => 300, 'total' => 300,
        ]);
        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'tracking_code' => 'ORD-202611147516417', 'status' => 'label_ready']);
        MelhorEnvioEnvio::create([
            'order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'me_order_id' => self::ME_ID,
            'servico' => 'JeT Standard', 'prazo_dias' => 5, 'gerada_em' => now()->subHour(),
        ]);

        return $order;
    }

    public function test_sem_postagem_so_grava_o_rastreio_da_transportadora(): void
    {
        $order = $this->pedido();
        $this->situacao = ['status' => 'released', 'tracking' => '888031040957845', 'posted_at' => null];

        $this->artisan('melhorenvio:acompanhar')->assertSuccessful();

        $this->assertSame('888031040957845', $order->channelShipment()->value('tracking_code'));
        $this->assertSame('released', MelhorEnvioEnvio::first()->situacao);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Queue::assertNotPushed(SendOrderShippedWhatsAppJob::class);
    }

    public function test_postado_vira_enviado_com_whatsapp_e_email_e_entregue_vira_concluido(): void
    {
        $order = $this->pedido();
        $this->situacao = ['status' => 'posted', 'tracking' => '888031040957845', 'posted_at' => now('UTC')->subHour()->format('Y-m-d H:i:s')];

        $this->artisan('melhorenvio:acompanhar')->assertSuccessful();

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        Queue::assertPushed(SendOrderShippedWhatsAppJob::class, fn ($j) => $j->orderId === $order->id && $j->previsao !== null);
        Queue::assertPushed(EnviarEmailPedidoEnviadoJob::class);

        $this->situacao = ['status' => 'delivered', 'tracking' => '888031040957845', 'posted_at' => now('UTC')->subDay()->format('Y-m-d H:i:s'), 'delivered_at' => now('UTC')->format('Y-m-d H:i:s')];
        $this->artisan('melhorenvio:acompanhar')->assertSuccessful();

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNotNull(MelhorEnvioEnvio::first()->entregue_em);
        Queue::assertPushed(SendOrderDeliveredWhatsAppJob::class, fn ($j) => $j->orderId === $order->id);
    }

    public function test_webhook_assinado_marca_enviado_e_assinatura_errada_e_recusada(): void
    {
        config(['services.melhorenvio.client_secret' => 'segredo-do-app']);
        $order = $this->pedido();
        $corpo = json_encode(['event' => 'order.posted', 'data' => [
            'id' => self::ME_ID, 'status' => 'posted', 'tracking' => '888031040957845',
            'posted_at' => now('UTC')->subMinutes(5)->toIso8601String(), 'delivered_at' => null,
        ]]);
        $assinatura = base64_encode(hash_hmac('sha256', $corpo, 'segredo-do-app', true));
        $enviar = fn (string $sig) => $this->call('POST', '/api/melhorenvio/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ME_SIGNATURE' => $sig], $corpo);

        $enviar('assinatura-falsa')->assertStatus(401);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        $enviar($assinatura)->assertOk()->assertJsonPath('resultado', 'enviados');
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame('888031040957845', $order->channelShipment()->value('tracking_code'));
        Queue::assertPushed(SendOrderShippedWhatsAppJob::class, fn ($j) => $j->orderId === $order->id);

        // Teste de cadastro da URL, sem dados: responde 200.
        $this->postJson('/api/melhorenvio/webhook', [])->assertOk();
    }
}
