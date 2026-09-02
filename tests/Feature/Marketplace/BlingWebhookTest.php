<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\ProcessBlingOrderWebhook;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Webhook do Bling (pedido de venda do TikTok Shop em tempo real). As
 * regras testadas aqui são exigências do próprio Bling, não escolhas
 * nossas: assinatura HMAC do corpo bruto, 2xx para entrega duplicada
 * (que ele avisa ser esperada) e 2xx para evento que não nos interessa —
 * qualquer resposta fora de 2xx entra em retentativa por 3 dias e depois
 * DESLIGA a configuração do webhook.
 */
class BlingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-do-app-bling';

    private const LOJA_TIKTOK = 205510;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.bling.client_secret' => self::SECRET]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'metadata' => ['tiktok_loja_id' => self::LOJA_TIKTOK],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'eventId' => '01945027-150e-72b4-e7cf-4943a042cd9c',
            'date' => '2026-09-02T12:18:46Z',
            'version' => 'v1',
            'event' => 'order.created',
            'companyId' => 'd4475854366a36c86a37e792f9634a51',
            'data' => [
                'id' => 6423813145,
                'numero' => 123,
                'numeroLoja' => '585839246415267075',
                'total' => 123.45,
                'loja' => ['id' => self::LOJA_TIKTOK],
                'situacao' => ['id' => 6, 'valor' => 6],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);

        return $this->call(
            'POST',
            '/api/bling/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BLING_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $raw, self::SECRET)],
            $raw,
        );
    }

    public function test_valid_event_of_the_tiktok_store_is_accepted_and_queued(): void
    {
        Queue::fake();

        $this->postWebhook($this->payload())->assertOk()->assertJson(['status' => 'received']);

        Queue::assertPushed(ProcessBlingOrderWebhook::class);
        $this->assertDatabaseHas('channel_webhook_logs', [
            'channel' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
            'event_type' => 'order.created',
            'status' => ChannelWebhookLog::STATUS_RECEIVED,
        ]);
    }

    public function test_event_with_a_wrong_signature_is_rejected_and_never_queued(): void
    {
        Queue::fake();

        $this->postWebhook($this->payload(), 'sha256=assinatura-errada')->assertStatus(401);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('channel_webhook_logs', ['status' => ChannelWebhookLog::STATUS_REJECTED]);
    }

    /**
     * O Bling avisa que a mesma entrega pode chegar 2x e que AS DUAS têm
     * que responder 2xx — a segunda não pode virar um segundo import.
     */
    public function test_duplicated_event_id_answers_2xx_but_is_processed_only_once(): void
    {
        Queue::fake();

        $this->postWebhook($this->payload())->assertOk();
        $this->postWebhook($this->payload())->assertOk()->assertJson(['status' => 'duplicate']);

        Queue::assertPushed(ProcessBlingOrderWebhook::class, 1);
    }

    /**
     * O webhook dispara pra toda a conta Bling, não só pra loja do TikTok
     * Shop. Descartar com 2xx, senão o Bling retenta por 3 dias um evento
     * que nunca vamos querer.
     */
    public function test_event_from_another_store_is_ignored_with_2xx(): void
    {
        Queue::fake();

        $payload = $this->payload();
        $payload['data']['loja']['id'] = 999999;

        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('channel_webhook_logs', ['status' => ChannelWebhookLog::STATUS_IGNORED]);
    }

    public function test_get_on_the_webhook_url_answers_ok_for_panel_checks(): void
    {
        $this->get('/api/bling/webhook')->assertOk()->assertJson(['status' => 'ok']);
    }
}
