<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_view_integrations(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/integracoes')->assertForbidden();
        $this->actingAs($admin)->get('/admin/integracoes')->assertOk();
    }

    public function test_admin_can_disconnect_mercado_livre(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456',
            'connected_at' => now(),
        ]);

        $this->actingAs($admin)->delete('/admin/integracoes/mercado_livre')->assertRedirect();
    }
}
