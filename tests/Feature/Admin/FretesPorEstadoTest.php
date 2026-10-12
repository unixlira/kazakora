<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FretesPorEstadoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function empresa(): void
    {
        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda',
            'nome_fantasia' => 'KazaKora',
            'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL,
            'phone' => '(11) 91234-5678',
            'email' => 'contato@kazakora.com',
            'zip' => '03187-040',
            'street' => 'Rua da Loja',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);
    }

    private function correiosConfigurado(): void
    {
        config([
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
        ]);
    }

    /** Responde como a API real: devolve um item por parâmetro do lote. */
    private function fakeCorreios(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'],
                    'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => $p['coProduto'] === '03220' ? '30,06' : '20,44',
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'],
                'nuRequisicao' => $p['nuRequisicao'],
                'prazoEntrega' => $p['coProduto'] === '03220' ? 1 : 5,
            ])->all());
        });
    }

    public function test_tela_abre_para_o_admin(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/logistica/fretes-por-estado')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/FretesPorEstado/Index', false)->has('produtos')->has('pacotePadrao'));
    }

    public function test_cota_as_27_capitais_em_lotes_de_5(): void
    {
        $this->empresa();
        $this->correiosConfigurado();
        $this->fakeCorreios();

        $resposta = $this->actingAs($this->admin())
            ->postJson('/admin/logistica/fretes-por-estado/cotar', ['peso_g' => 500, 'comprimento' => 20, 'largura' => 15, 'altura' => 10])
            ->assertOk()
            ->assertJsonPath('origem', '03187040')
            ->assertJsonPath('erro', null)
            ->assertJsonCount(27, 'linhas');

        $sp = collect($resposta->json('linhas'))->firstWhere('uf', 'SP');
        $this->assertSame(20.44, $sp['pac']['preco']);
        $this->assertSame(5, $sp['pac']['prazo']);
        $this->assertSame(30.06, $sp['sedex']['preco']);
        $this->assertSame(1, $sp['sedex']['prazo']);

        // 54 preços + 54 prazos, no máximo 5 por lote (limite da API).
        Http::assertSentCount(22);
        Http::assertSent(fn (Request $r) => count($r['parametrosProduto'] ?? $r['parametrosPrazo']) <= 5);
    }

    public function test_usa_o_cache_na_segunda_consulta(): void
    {
        $this->empresa();
        $this->correiosConfigurado();
        $this->fakeCorreios();
        $admin = $this->admin();
        $pacote = ['peso_g' => 800, 'comprimento' => 25, 'largura' => 20, 'altura' => 10];

        $this->actingAs($admin)->postJson('/admin/logistica/fretes-por-estado/cotar', $pacote)->assertOk();
        $this->actingAs($admin)->postJson('/admin/logistica/fretes-por-estado/cotar', $pacote)->assertOk();

        Http::assertSentCount(22);
    }

    public function test_valida_os_limites_dos_correios(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/logistica/fretes-por-estado/cotar', ['peso_g' => 40000, 'comprimento' => 10, 'largura' => 15, 'altura' => 10])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['peso_g', 'comprimento']]);
    }

    public function test_sem_integracao_avisa_em_vez_de_quebrar(): void
    {
        $this->empresa();
        config(['services.correios.numero_usuario' => null]);
        Http::fake();

        $this->actingAs($this->admin())
            ->postJson('/admin/logistica/fretes-por-estado/cotar', ['peso_g' => 500, 'comprimento' => 20, 'largura' => 15, 'altura' => 10])
            ->assertOk()
            ->assertJsonPath('erro', 'A integração com os Correios não está configurada.')
            ->assertJsonCount(0, 'linhas');

        Http::assertNothingSent();
    }
}
