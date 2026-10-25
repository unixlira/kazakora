<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Checkout\Models\FreteCategoriaPacote;
use App\Modules\Checkout\Models\FreteRegiao;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\RegrasDeFrete;
use App\Modules\Fiscal\Models\Company;
use App\Support\SiteAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Frete por cidade (pedido 2026-10-24): regra por município e categoria de
 * pacote na tela Fretes por cidade, aplicada no frete embutido da loja, e a
 * trava do frete por IP e aparelho. Mauá com frete grátis.
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

    public function test_tela_lista_as_cidades_do_estado_com_a_regra_de_cada_categoria(): void
    {
        $this->regraNaCidade(self::MAUA, FreteRegiao::FIXO, 0);
        $this->regraNaCidade(self::MAUA, FreteRegiao::TETO, 20)->update(['categoria' => FreteCategoriaPacote::EXTRA_GRANDE]);

        $this->actingAs($this->admin())
            ->get('/admin/logistica/fretes-por-cidade?uf=sp')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FretesPorCidade/Index', false)
                ->where('uf', 'SP')
                ->where('site', 'kazakora')
                ->has('categorias', 4)
                ->where('cidades.0.nome', 'Mauá')
                ->where('cidades.0.regras.todas.tipo_preco', 'fixo')
                ->where('cidades.0.regras.extra_grande.valor', 20)
                ->where('cidades.0.regras.pequena', null)
                ->where('cidades.1.nome', 'São Paulo')
                ->where('cidades.1.regras.todas', null)
                ->where('estados.0.com_regra', 1));

        $this->actingAs($this->admin())
            ->get('/admin/logistica/fretes-por-cidade/'.self::MAUA)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FretesPorCidade/Cidade', false)
                ->where('cidade.nome', 'Mauá')
                ->where('regras.extra_grande.tipo_preco', 'teto'));
    }

    public function test_cria_troca_e_tira_a_regra_da_cidade_por_categoria(): void
    {
        $admin = $this->admin();
        $url = '/admin/logistica/fretes-por-cidade/'.self::MAUA.'/regra';

        $this->actingAs($admin)
            ->put($url, ['site' => 'kazakora', 'categoria' => null, 'tipo_preco' => 'teto', 'valor' => 12.5, 'prazo_dias' => 2])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put($url, ['site' => 'kazakora', 'categoria' => null, 'tipo_preco' => 'fixo', 'valor' => 0])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put($url, ['site' => 'kazakora', 'categoria' => 'grande', 'tipo_preco' => 'fixo', 'valor' => 15])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, FreteRegiao::count());
        $this->assertDatabaseHas('frete_regioes', ['nome' => 'Mauá - SP', 'categoria' => null, 'tipo_preco' => 'fixo', 'valor' => 0, 'prazo_dias' => null, 'site' => 'kazakora']);
        $this->assertDatabaseHas('frete_regioes', ['nome' => 'Mauá - SP (Grande)', 'categoria' => 'grande', 'valor' => 15]);

        $this->actingAs($admin)
            ->put($url, ['site' => 'kazakora', 'tipo_preco' => 'qualquer', 'valor' => -1])
            ->assertSessionHasErrors(['tipo_preco', 'valor']);
        $this->actingAs($admin)
            ->put($url, ['site' => 'kazakora', 'categoria' => 'gigante', 'tipo_preco' => 'fixo', 'valor' => 1])
            ->assertSessionHasErrors('categoria');

        $this->actingAs($admin)
            ->delete($url, ['site' => 'kazakora', 'categoria' => 'grande'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, FreteRegiao::count());
        $this->assertDatabaseCount('frete_regiao_locais', 1);
    }

    public function test_limites_das_categorias_precisam_crescer(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put('/admin/logistica/fretes-por-cidade/categorias', ['categorias' => [
                ['chave' => 'pequena', 'peso_max_g' => 800, 'lado_max_cm' => 25],
                ['chave' => 'media', 'peso_max_g' => 4000, 'lado_max_cm' => 50],
                ['chave' => 'grande', 'peso_max_g' => 10000, 'lado_max_cm' => 70],
            ]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('frete_categorias_pacote', ['chave' => 'pequena', 'peso_max_g' => 800, 'lado_max_cm' => 25]);
        $this->assertDatabaseHas('frete_categorias_pacote', ['chave' => 'extra_grande', 'peso_max_g' => null]);

        $this->actingAs($admin)
            ->put('/admin/logistica/fretes-por-cidade/categorias', ['categorias' => [
                ['chave' => 'media', 'peso_max_g' => 500, 'lado_max_cm' => 50],
            ]])
            ->assertSessionHasErrors('categorias');
        $this->assertDatabaseHas('frete_categorias_pacote', ['chave' => 'media', 'peso_max_g' => 4000]);
    }

    public function test_categorias_padrao_pela_escada_de_7_metros(): void
    {
        $this->assertSame('pequena', FreteCategoriaPacote::classificar(500, 20));
        $this->assertSame('media', FreteCategoriaPacote::classificar(500, 45));
        $this->assertSame('grande', FreteCategoriaPacote::classificar(9000, 70));
        // Escada telescópica de 7 m: cerca de 95 x 48 x 10 cm e 17 kg.
        $this->assertSame('extra_grande', FreteCategoriaPacote::classificar(17000, 95));
    }

    public function test_regra_da_categoria_passa_na_frente_da_regra_de_todas(): void
    {
        $this->comCorreiosEViaCep();
        $pequeno = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $escada = Product::factory()->create(['price' => 600, 'stock' => 5, 'is_active' => true]);
        ProductFiscalData::create(['product_id' => $pequeno->id, 'peso_bruto' => 0.3, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);
        ProductFiscalData::create(['product_id' => $escada->id, 'peso_bruto' => 17, 'altura_cm' => 10, 'largura_cm' => 48, 'profundidade_cm' => 95]);
        $this->regraNaCidade(self::MAUA, FreteRegiao::FIXO, 0);
        $this->regraNaCidade(self::MAUA, FreteRegiao::FIXO, 20)->update(['categoria' => FreteCategoriaPacote::EXTRA_GRANDE]);
        RegrasDeFrete::esquecer();

        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep('09310-000');

        $this->assertSame('pequena', $frete->categoria($pequeno));
        $this->assertSame('extra_grande', $frete->categoria($escada));
        $this->assertSame(0.0, $frete->paraProduto($pequeno)['embutido']);
        $this->assertSame(20.0, $frete->paraProduto($escada)['embutido']);

        // Caixa com os dois: o maior lado é o da escada, vale a Extra Grande.
        $entrega = $frete->entregaDoCarrinho(collect([['product' => $pequeno, 'quantity' => 1], ['product' => $escada, 'quantity' => 1]]));
        $this->assertSame('Teste', $entrega['regra']);
    }

    public function test_frete_calculado_fica_travado_para_o_mesmo_ip_e_aparelho(): void
    {
        $this->comCorreiosEViaCep();
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $aparelho = ['User-Agent' => 'Mozilla/5.0 (Celular do cliente)', 'Accept-Language' => 'pt-BR'];

        $this->withHeaders($aparelho)->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 109.94);
        $this->assertDatabaseHas('frete_travas', ['cep' => '01001000', 'product_id' => $produto->id, 'embutido' => 9.94, 'site' => 'kazakora']);

        // O frete da cidade sobe depois: o mesmo visitante continua com o frete que viu.
        $this->regraNaCidade(self::SAO_PAULO, FreteRegiao::ACRESCIMO, 10);
        $this->flushSession();
        $this->withHeaders($aparelho)->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 109.94);

        // Outro aparelho na mesma rede paga o frete de agora.
        $this->flushSession();
        $this->withHeaders(['User-Agent' => 'Outro navegador', 'Accept-Language' => 'en-US'])->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 119.94);

        // Se o frete cair, o visitante travado paga o menor.
        FreteRegiao::query()->update(['tipo_preco' => FreteRegiao::FIXO, 'valor' => 0]);
        RegrasDeFrete::esquecer();
        $this->flushSession();
        $this->withHeaders($aparelho)->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 100);
    }

    public function test_trava_vencida_ou_desligada_nao_segura_o_frete(): void
    {
        $this->comCorreiosEViaCep();
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);

        $this->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)->assertJsonPath('final_price', 109.94);
        $this->regraNaCidade(self::SAO_PAULO, FreteRegiao::ACRESCIMO, 10);

        $this->travel(31)->days();
        $this->flushSession();
        $this->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)->assertJsonPath('final_price', 119.94);
        // A trava renovada guarda o frete novo.
        $this->assertDatabaseHas('frete_travas', ['product_id' => $produto->id, 'embutido' => 19.94]);

        config(['frete_embutido.trava_dias' => 0]);
        FreteRegiao::query()->update(['valor' => 20]);
        RegrasDeFrete::esquecer();
        $this->flushSession();
        $this->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)->assertJsonPath('final_price', 129.94);
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
