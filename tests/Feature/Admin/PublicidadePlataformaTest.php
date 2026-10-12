<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu Publicidade, seções Meta ADS e Google ADS (2026-10-12): sem
 * integração, a tela mostra "não conectada" e zero; com gasto
 * sincronizado em channel_ad_spends, mostra os números da plataforma.
 */
class PublicidadePlataformaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_meta_ads_sem_integracao_mostra_nao_conectada(): void
    {
        // Gasto de outro canal não pode aparecer na tela da Meta.
        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'shopee', 'spend' => 50, 'attributed_gmv' => 200]);

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->where('plataforma.canal', 'meta_ads')
                ->where('plataforma.nome', 'Meta ADS')
                ->where('conectado', false)
                ->where('ultimaSincronizacao', null)
                ->where('periodos.0.totais.gasto', 0)
                ->where('periodos.0.totais.roas', null));
    }

    public function test_google_ads_sem_integracao_mostra_nao_conectada(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/google-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->where('plataforma.canal', 'google_ads')
                ->where('plataforma.nome', 'Google ADS')
                ->where('conectado', false));
    }

    public function test_com_gasto_sincronizado_mostra_os_numeros_da_plataforma(): void
    {
        ChannelAdSpend::create([
            'date' => now()->toDateString(),
            'channel' => 'google_ads',
            'impressions' => 1000,
            'clicks' => 40,
            'attributed_orders' => 3,
            'attributed_gmv' => 300,
            'spend' => 100,
        ]);

        $this->actingAs($this->admin())->get('/admin/publicidade/google-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('conectado', true)
                ->where('ultimaSincronizacao', now()->toDateString())
                ->where('periodos.0.totais.gasto', 100)
                ->where('periodos.0.totais.cliques', 40)
                ->where('periodos.0.totais.roas', 3)
                ->where('periodos.1.totais.gasto', 0));
    }

    public function test_cliente_nao_acessa(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente)->get('/admin/publicidade/meta-ads')->assertStatus(403);
    }
}
