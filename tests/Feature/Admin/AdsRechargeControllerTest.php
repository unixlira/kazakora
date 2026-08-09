<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\AdsRecharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-09: histórico de recarga de anúncio (Shopee +
 * Mercado Livre), lançado à mão já que nenhuma API expõe esse extrato.
 */
class AdsRechargeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_can_register_a_recharge_and_see_it_in_the_summary(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/anuncios/recargas', [
            'channel' => 'shopee',
            'amount' => 150.5,
            'recharge_date' => now()->toDateString(),
            'notes' => 'Recarga via cartão',
        ])->assertRedirect();

        $recharge = AdsRecharge::query()->firstOrFail();
        $this->assertSame('shopee', $recharge->channel);
        $this->assertSame($admin->id, $recharge->created_by);

        $response = $this->actingAs($admin)->get('/admin/anuncios/recargas');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('recharges', 1)
            ->where('summary.shopee', 150.5)
            ->where('summary.mercado_livre', 0));
    }

    public function test_manager_cannot_delete_a_recharge_but_admin_can(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = $this->admin();

        $recharge = AdsRecharge::create([
            'channel' => 'mercado_livre',
            'amount' => 80,
            'recharge_date' => now()->toDateString(),
        ]);

        $this->actingAs($manager)->delete("/admin/anuncios/recargas/{$recharge->id}")->assertForbidden();
        $this->assertDatabaseHas('ads_recharges', ['id' => $recharge->id]);

        $this->actingAs($admin)->delete("/admin/anuncios/recargas/{$recharge->id}")->assertRedirect();
        $this->assertDatabaseMissing('ads_recharges', ['id' => $recharge->id]);
    }

    public function test_invalid_channel_is_rejected(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/anuncios/recargas', [
            'channel' => 'amazon',
            'amount' => 10,
            'recharge_date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('channel');
        $this->assertSame(0, AdsRecharge::count());
    }
}
