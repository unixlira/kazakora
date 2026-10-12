<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\MelhorEnvioToken;
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
            ->assertInertia(fn ($page) => $page->component('Admin/FretesPorEstado/Index', false)->has('produtos')->has('pacotePadrao')->where('melhorEnvio.conectado', false));
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

    private function tokenMelhorEnvio(bool $vencido = false): void
    {
        MelhorEnvioToken::create([
            'account_label' => 'KazaKora',
            'access_token' => 'token-me',
            'refresh_token' => 'refresh-me',
            'token_expires_at' => $vencido ? now()->subDay() : now()->addDays(10),
            'scopes' => ['shipping-calculate'],
        ]);
    }

    public function test_melhor_envio_cota_as_27_capitais_com_a_mais_barata_primeiro(): void
    {
        $this->empresa();
        $this->tokenMelhorEnvio();
        config(['services.melhorenvio.api_base_url' => 'https://sandbox.melhorenvio.test/api/v2']);
        Http::fake(['*/me/shipment/calculate' => Http::response([
            ['id' => 1, 'name' => 'PAC', 'price' => '22.50', 'delivery_time' => 7, 'company' => ['name' => 'Correios']],
            ['id' => 3, 'name' => '.Package', 'price' => '14.90', 'delivery_time' => 5, 'company' => ['name' => 'Jadlog']],
            ['id' => 4, 'name' => '.Com', 'error' => 'Serviço indisponível para o trecho.', 'company' => ['name' => 'Jadlog']],
        ])]);

        $resposta = $this->actingAs($this->admin())
            ->postJson('/admin/logistica/fretes-por-estado/cotar-melhor-envio', ['peso_g' => 300, 'comprimento' => 16, 'largura' => 11, 'altura' => 8, 'valor' => 49.9])
            ->assertOk()
            ->assertJsonPath('ambiente', 'sandbox')
            ->assertJsonPath('erro', null)
            ->assertJsonCount(27, 'linhas');

        $ce = collect($resposta->json('linhas'))->firstWhere('uf', 'CE');
        $this->assertCount(2, $ce['opcoes']);
        $this->assertSame('Jadlog', $ce['opcoes'][0]['transportadora']);
        $this->assertSame(14.9, $ce['opcoes'][0]['preco']);

        Http::assertSentCount(27);
        Http::assertSent(fn (Request $r) => $r['products'][0]['weight'] === 0.3 && $r['products'][0]['insurance_value'] === 49.9 && $r['from']['postal_code'] === '03187040');
    }

    public function test_melhor_envio_com_acesso_vencido_pede_para_conectar_de_novo(): void
    {
        $this->empresa();
        $this->tokenMelhorEnvio(vencido: true);
        Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_request', 'message' => 'The refresh token is invalid.'], 401)]);

        $this->actingAs($this->admin())
            ->postJson('/admin/logistica/fretes-por-estado/cotar-melhor-envio', ['peso_g' => 300, 'comprimento' => 16, 'largura' => 11, 'altura' => 8])
            ->assertOk()
            ->assertJsonPath('reconectar', true)
            ->assertJsonPath('erro', 'O acesso ao Melhor Envio venceu. Conecte a conta de novo.')
            ->assertJsonCount(0, 'linhas');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'shipment/calculate'));
    }
}
