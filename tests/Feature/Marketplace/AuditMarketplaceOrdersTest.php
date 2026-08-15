<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Jobs\AuditMarketplaceOrdersJob;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditMarketplaceOrdersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pedido explícito 2026-08-15 ("faça assíncrono") — o comando só
     * enfileira, nunca roda a reconciliação na própria requisição/console.
     */
    public function test_command_dispatches_the_audit_job_instead_of_running_inline(): void
    {
        Queue::fake();

        Artisan::call('orders:audit', ['--dias' => 30]);

        Queue::assertPushed(AuditMarketplaceOrdersJob::class, fn ($job) => $job->days === 30);
    }

    public function test_command_defaults_to_90_days(): void
    {
        Queue::fake();

        Artisan::call('orders:audit');

        Queue::assertPushed(AuditMarketplaceOrdersJob::class, fn ($job) => $job->days === 90);
    }

    /**
     * Canal desconectado (sem MarketplaceAccount) nunca deve tentar chamar
     * o comando de sync dele — e um canal conectado sem token/API real
     * configurada (caso deste teste) não pode derrubar o job inteiro, só
     * loga e segue pro próximo canal.
     */
    public function test_job_never_throws_regardless_of_channel_connection_state(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
        ]);
        // Mercado Livre/Amazon sem MarketplaceAccount nenhuma — "desconectados".

        (new AuditMarketplaceOrdersJob(days: 7))->handle();

        $this->assertTrue(true); // chegou até aqui sem exceção — é a garantia real que este job precisa dar
    }
}
