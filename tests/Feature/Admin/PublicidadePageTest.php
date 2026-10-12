<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Menu Publicidade (2026-10-12): resumo de gasto de ADS por canal no mês
 * atual e no anterior, a partir de channel_ad_spends.
 */
class PublicidadePageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_resume_gasto_e_roas_por_canal_no_mes_atual_e_anterior(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');

        ChannelAdSpend::create(['date' => '2026-10-01', 'channel' => 'shopee', 'impressions' => 1000, 'clicks' => 50, 'attributed_orders' => 3, 'attributed_gmv' => 300, 'spend' => 60]);
        ChannelAdSpend::create(['date' => '2026-10-05', 'channel' => 'shopee', 'impressions' => 500, 'clicks' => 10, 'attributed_orders' => 1, 'attributed_gmv' => 100, 'spend' => 40]);
        ChannelAdSpend::create(['date' => '2026-10-03', 'channel' => 'mercado_livre', 'impressions' => 200, 'clicks' => 5, 'attributed_orders' => 2, 'attributed_gmv' => 0, 'spend' => 25]);
        ChannelAdSpend::create(['date' => '2026-09-20', 'channel' => 'shopee', 'impressions' => 10, 'clicks' => 1, 'attributed_orders' => 1, 'attributed_gmv' => 90, 'spend' => 30]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/publicidade')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Index', false)
                ->where('periodos.0.desde', '2026-10-01')
                ->where('periodos.0.ate', '2026-10-12')
                ->has('periodos.0.canais', 2)
                ->where('periodos.0.canais.1.canal', 'shopee')
                ->where('periodos.0.canais.1.gasto', 100)
                ->where('periodos.0.canais.1.cliques', 60)
                ->where('periodos.0.canais.1.roas', 4)
                ->where('periodos.0.canais.0.roas', null)
                ->where('periodos.1.desde', '2026-09-01')
                ->has('periodos.1.canais', 1)
                ->where('periodos.1.canais.0.roas', 3));
    }

    public function test_cliente_nao_ve(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente)->get('/admin/publicidade')->assertForbidden();
    }
}
