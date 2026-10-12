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
        Http::fake([
            CotacaoDolarService::FONTE => Http::response('erro', 500),
            CotacaoDolarService::FONTE_RESERVA => Http::response('erro', 500),
        ]);

        $this->actingAs($this->admin())->get('/admin/calculadora-importacao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('cotacao', null));
    }

    public function test_usa_a_exchangerate_api_quando_a_awesomeapi_bloqueia(): void
    {
        // AwesomeAPI responde 429 para IP de servidor (visto no homolog e na VPS).
        Http::fake([
            CotacaoDolarService::FONTE => Http::response(['status' => 429, 'code' => 'QuotaExceeded'], 429),
            CotacaoDolarService::FONTE_RESERVA => Http::response([
                'result' => 'success',
                'time_last_update_unix' => 1791763352,
                'rates' => ['USD' => 1, 'BRL' => 4.9861],
            ]),
        ]);

        $this->actingAs($this->admin())->get('/admin/calculadora-importacao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cotacao.valor', 4.9861)
                ->where('cotacao.fonte', 'exchangerate-api')
                ->where('cotacao.atualizado_em', '2026-10-11T21:02:32-03:00'));
    }

    public function test_atualizar_ignora_o_cache(): void
    {
        $resposta = fn (string $bid) => ['USDBRL' => ['bid' => $bid, 'create_date' => '2026-10-12 11:27:17']];
        Http::fake([CotacaoDolarService::FONTE => Http::sequence()
            ->push($resposta('4.9000'))
            ->push($resposta('5.1234'))]);

        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/calculadora-importacao')
            ->assertInertia(fn ($page) => $page->where('cotacao.valor', 4.9));

        $this->actingAs($admin)->getJson('/admin/calculadora-importacao/cotacao')
            ->assertOk()
            ->assertJson(['valor' => 5.1234, 'fonte' => 'AwesomeAPI']);
    }

    public function test_atualizar_responde_503_quando_a_api_falha(): void
    {
        Http::fake([
            CotacaoDolarService::FONTE => Http::response([], 200),
            CotacaoDolarService::FONTE_RESERVA => Http::response([], 200),
        ]);

        $this->actingAs($this->admin())->getJson('/admin/calculadora-importacao/cotacao')
            ->assertStatus(503);
    }

    public function test_visitante_nao_entra(): void
    {
        $this->get('/admin/calculadora-importacao')->assertRedirect();
    }
}
