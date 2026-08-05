<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\User;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookReprocessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprocessing_an_ignored_post_purchase_log_now_processes_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Simula o cenário real: webhook chegou quando post_purchase ainda
        // não era tratado, ficou marcado como ignorado.
        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'event_type' => 'post_purchase',
            'payload' => ['topic' => 'post_purchase', 'resource' => '/post-purchase/v1/claims/999'],
            'status' => ChannelWebhookLog::STATUS_IGNORED,
        ]);

        // A chamada real à API do claim vai falhar (sem token/mock em teste
        // de integração HTTP) — o que importa aqui é confirmar que o
        // reprocessamento roda o handler de verdade (status sai de
        // "ignored") e não quebra a requisição, não o resultado exato do
        // claim (isso já é coberto por ClaimServiceTest com o client mockado).
        $response = $this->actingAs($admin)->post("/admin/integracoes/webhooks/{$log->id}/reprocessar");

        $response->assertRedirect();
        $this->assertNotSame(ChannelWebhookLog::STATUS_IGNORED, $log->fresh()->status);
    }

    public function test_reprocessing_a_non_mercado_livre_log_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'event_type' => 'order_status_update',
            'payload' => [],
            'status' => ChannelWebhookLog::STATUS_IGNORED,
        ]);

        $this->actingAs($admin)
            ->post("/admin/integracoes/webhooks/{$log->id}/reprocessar")
            ->assertRedirect();

        $this->assertSame(ChannelWebhookLog::STATUS_IGNORED, $log->fresh()->status);
    }
}
