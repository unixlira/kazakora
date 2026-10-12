<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Services\CotacaoDolarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Calculadora de importação (2026-10-12): a tela abre com o dólar do dia e o
 * botão "Atualizar" busca de novo, sem cache. A conta em si fica na tela.
 */
class CalculadoraImportacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function fakeCotacao(string $bid): void
    {
        Http::fake([CotacaoDolarService::FONTE => Http::response([
            'USDBRL' => ['code' => 'USD', 'codein' => 'BRL', 'bid' => $bid, 'create_date' => '2026-10-12 11:27:17'],
        ])]);
    }

    public function test_tela_abre_com_a_cotacao_do_dolar(): void
    {
        $this->fakeCotacao('4.9863');

        $this->actingAs($this->admin())->get('/admin/calculadora-importacao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/CalculadoraImportacao/Index', false)
                ->where('cotacao.valor', 4.9863)
                ->where('cotacao.atualizado_em', '2026-10-12T11:27:17-03:00'));
    }

    public function test_tela_abre_sem_cotacao_quando_a_api_falha(): void
    {
        Http::fake([CotacaoDolarService::FONTE => Http::response('erro', 500)]);

        $this->actingAs($this->admin())->get('/admin/calculadora-importacao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('cotacao', null));
    }

    public function test_atualizar_ignora_o_cache(): void
    {
        $this->fakeCotacao('5.0000');
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/calculadora-importacao')->assertOk();

        $this->fakeCotacao('5.1234');

        $this->actingAs($admin)->getJson('/admin/calculadora-importacao/cotacao')
            ->assertOk()
            ->assertJson(['valor' => 5.1234, 'fonte' => 'AwesomeAPI']);
    }

    public function test_atualizar_responde_503_quando_a_api_falha(): void
    {
        Http::fake([CotacaoDolarService::FONTE => Http::response([], 200)]);

        $this->actingAs($this->admin())->getJson('/admin/calculadora-importacao/cotacao')
            ->assertStatus(503);
    }

    public function test_visitante_nao_entra(): void
    {
        $this->get('/admin/calculadora-importacao')->assertRedirect();
    }
}
