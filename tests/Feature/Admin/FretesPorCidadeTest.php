<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteRegiao;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\RegrasDeFrete;
use App\Modules\Fiscal\Models\Company;
use App\Support\SiteAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frete por cidade (pedido 2026-10-24): regra por município na tela Fretes
 * por estado, aplicada no frete embutido da loja. Mauá com frete grátis.
 */
class FretesPorCidadeTest extends TestCase
{
    use RefreshDatabase;

    private const MAUA = 3529401;

    private const SAO_PAULO = 3550308;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => true]);

        $agora = now();
        DB::table('ibge_estados')->insert(['id' => 35, 'sigla' => 'SP', 'nome' => 'São Paulo', 'created_at' => $agora, 'updated_at' => $agora]);
        DB::table('ibge_municipios')->insert([
            ['id' => self::MAUA, 'nome' => 'Mauá', 'nome_normalizado' => 'maua', 'estado_id' => 35, 'uf' => 'SP', 'created_at' => $agora, 'updated_at' => $agora],
            ['id' => self::SAO_PAULO, 'nome' => 'São Paulo', 'nome_normalizado' => 'sao paulo', 'estado_id' => 35, 'uf' => 'SP', 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function comCorreiosEViaCep(): void
    {
        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        config([
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
        ]);

        // PAC R$ 15,45 e SEDEX R$ 9,94 (o SEDEX vai embutido) em qualquer peso.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'viacep.com.br/ws/09310000')) {
                return Http::response(['cep' => '09310-000', 'localidade' => 'Mauá', 'uf' => 'SP', 'ibge' => (string) self::MAUA]);
            }
            if (str_contains($request->url(), 'viacep.com.br/ws/01001000')) {
                return Http::response(['cep' => '01001-000', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => (string) self::SAO_PAULO]);
            }
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'pcFinal' => $p['coProduto'] === '03220' ? '9,94' : '15,45',
                ])->all());
            }
            if (str_contains($request->url(), '/prazo/')) {
                return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'prazoEntrega' => $p['coProduto'] === '03220' ? 1 : 3,
                ])->all());
            }

            return Http::response([], 404);
        });
    }

    private function regraNaCidade(int $municipio, string $tipo, float $valor): FreteRegiao
    {
        $regra = FreteRegiao::create(['site' => SiteAtual::KAZAKORA, 'nome' => 'Teste', 'tipo_preco' => $tipo, 'valor' => $valor]);
        $regra->locais()->create(['municipio_id' => $municipio]);
        RegrasDeFrete::esquecer();

        return $regra;
    }

    public function test_lista_as_cidades_do_estado_com_a_regra_de_cada_uma(): void
    {
        $this->regraNaCidade(self::MAUA, FreteRegiao::FIXO, 0);

        $this->actingAs($this->admin())
            ->getJson('/admin/logistica/fretes-por-estado/sp/cidades')
            ->assertOk()
            ->assertJsonPath('uf', 'SP')
            ->assertJsonPath('cidades.0.nome', 'Mauá')
            ->assertJsonPath('cidades.0.regra.tipo_preco', 'fixo')
            ->assertJsonPath('cidades.0.regra.valor', 0)
            ->assertJsonPath('cidades.1.nome', 'São Paulo')
            ->assertJsonPath('cidades.1.regra', null);
    }

    public function test_cria_troca_e_tira_a_regra_da_cidade(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/admin/logistica/fretes-por-estado/cidades/'.self::MAUA, ['tipo_preco' => 'teto', 'valor' => 12.5, 'prazo_dias' => 2])
            ->assertOk()
            ->assertJsonPath('regra.tipo_preco', 'teto')
            ->assertJsonPath('regra.prazo_dias', 2);

        $this->actingAs($admin)
            ->putJson('/admin/logistica/fretes-por-estado/cidades/'.self::MAUA, ['tipo_preco' => 'fixo', 'valor' => 0])
            ->assertOk();

        $this->assertSame(1, FreteRegiao::count());
        $this->assertDatabaseHas('frete_regioes', ['nome' => 'Mauá - SP', 'tipo_preco' => 'fixo', 'valor' => 0, 'prazo_dias' => null, 'site' => 'kazakora']);
        $this->assertDatabaseHas('frete_regiao_locais', ['municipio_id' => self::MAUA]);

        $this->actingAs($admin)
            ->putJson('/admin/logistica/fretes-por-estado/cidades/'.self::MAUA, ['tipo_preco' => 'qualquer', 'valor' => -1])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson('/admin/logistica/fretes-por-estado/cidades/'.self::MAUA)
            ->assertOk();

        $this->assertSame(0, FreteRegiao::count());
        $this->assertDatabaseCount('frete_regiao_locais', 0);
    }

    public function test_maua_com_frete_gratis_tira_o_frete_do_preco_e_sao_paulo_continua_pagando(): void
    {
        $this->comCorreiosEViaCep();
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $this->regraNaCidade(self::MAUA, FreteRegiao::FIXO, 0);

        $this->getJson('/frete/prazo?cep=09310000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 100);

        $this->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 109.94);
    }

    public function test_carrinho_cobra_a_regra_uma_vez_pela_caixa_e_mantem_o_custo_real(): void
    {
        $this->comCorreiosEViaCep();
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $this->regraNaCidade(self::MAUA, FreteRegiao::ACRESCIMO, 5);

        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep('09310-000');

        $this->assertSame(14.94, $frete->paraProduto($produto)['embutido']);

        $entrega = $frete->entregaDoCarrinho(collect([['product' => $produto, 'quantity' => 2]]));
        // 2 x 14,94 embutido; a caixa paga 9,94 + 5 uma vez só: volta 14,94.
        $this->assertSame(14.94, $entrega['desconto_plataforma']);
        $this->assertSame(9.94, $entrega['custo']);
        $this->assertSame('Teste', $entrega['regra']);
    }

    public function test_regra_de_outro_site_ou_inativa_nao_vale(): void
    {
        $this->comCorreiosEViaCep();
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $this->regraNaCidade(self::MAUA, FreteRegiao::FIXO, 0)->update(['site' => SiteAtual::FERRAKORA]);
        $this->regraNaCidade(self::MAUA, FreteRegiao::FIXO, 1)->update(['ativo' => false]);
        RegrasDeFrete::esquecer();

        $this->getJson('/frete/prazo?cep=09310000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 109.94);
    }

    public function test_tipos_de_regra(): void
    {
        $regra = fn ($tipo, $valor) => ['tipo' => $tipo, 'valor' => $valor];

        $this->assertSame(0.0, RegrasDeFrete::aplicar($regra('fixo', 0), 20));
        $this->assertSame(25.0, RegrasDeFrete::aplicar($regra('acrescimo', 5), 20));
        $this->assertSame(15.0, RegrasDeFrete::aplicar($regra('desconto', 5), 20));
        $this->assertSame(0.0, RegrasDeFrete::aplicar($regra('desconto', 50), 20));
        $this->assertSame(12.0, RegrasDeFrete::aplicar($regra('teto', 12), 20));
        $this->assertSame(8.0, RegrasDeFrete::aplicar($regra('teto', 12), 8));
    }
}
