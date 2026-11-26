<?php

namespace Tests\Feature\Seguranca;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Webhooks com segredo configurado recusam aviso sem assinatura ou com
 * assinatura errada (auditoria 2026-11-25).
 */
class WebhooksFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_mercado_pago_sem_assinatura_recusa(): void
    {
        config(['services.mercadopago.webhook_secret' => 'segredo-mp']);

        $this->postJson('/api/mercadopago/webhook?data_id=123', ['type' => 'payment', 'data' => ['id' => '123']])
            ->assertStatus(401);
    }

    public function test_mercado_pago_com_assinatura_errada_recusa(): void
    {
        config(['services.mercadopago.webhook_secret' => 'segredo-mp']);

        $this->postJson('/api/mercadopago/webhook?data_id=123', ['type' => 'payment', 'data' => ['id' => '123']], [
            'x-signature' => 'ts=1700000000,v1='.str_repeat('a', 64),
            'x-request-id' => 'req-1',
        ])->assertStatus(401);
    }

    public function test_mercado_pago_com_assinatura_certa_passa_da_trava(): void
    {
        config(['services.mercadopago.webhook_secret' => 'segredo-mp']);
        $v1 = hash_hmac('sha256', 'id:123;request-id:req-1;ts:1700000000;', 'segredo-mp');

        $resposta = $this->postJson('/api/mercadopago/webhook?data_id=123', ['type' => 'tipo-que-nao-tratamos', 'data' => ['id' => '123']], [
            'x-signature' => 'ts=1700000000,v1='.$v1,
            'x-request-id' => 'req-1',
        ]);

        $this->assertNotSame(401, $resposta->getStatusCode());
    }

    public function test_whatsapp_sem_assinatura_recusa(): void
    {
        config(['services.whatsapp.app_secret' => 'segredo-meta']);

        $this->postJson('/api/whatsapp/webhook', ['object' => 'whatsapp_business_account', 'entry' => []])
            ->assertForbidden();
    }

    public function test_whatsapp_com_assinatura_errada_recusa(): void
    {
        config(['services.whatsapp.app_secret' => 'segredo-meta']);

        $this->postJson('/api/whatsapp/webhook', ['object' => 'whatsapp_business_account', 'entry' => []], [
            'X-Hub-Signature-256' => 'sha256='.str_repeat('0', 64),
        ])->assertForbidden();
    }

    public function test_whatsapp_com_assinatura_certa_aceita(): void
    {
        config(['services.whatsapp.app_secret' => 'segredo-meta']);
        $corpo = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

        $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo-meta'),
        ], $corpo)->assertOk();
    }

    public function test_melhor_envio_sem_assinatura_recusa(): void
    {
        config(['services.melhorenvio.client_secret' => 'segredo-me']);

        $this->postJson('/api/melhorenvio/webhook', ['event' => 'order.posted', 'data' => ['id' => 'x']])
            ->assertStatus(401);
    }

    public function test_kiwify_sem_assinatura_recusa(): void
    {
        config(['infoprodutos.kiwify.tokens' => ['token-kiwify']]);

        $this->postJson('/api-infoprodutos/kiwify/webhook', ['order_id' => 'x'])->assertStatus(401);
    }
}
