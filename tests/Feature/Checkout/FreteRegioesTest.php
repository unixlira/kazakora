<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteRegiao;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\FreteRegioes;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frete por cidade/região (pedido 2026-10-24): a regra do CEP mexe na
 * cotação dos Correios que o FreteEmbutido usa (vitrine, produto, checkout).
 * Ceará: PAC R$ 20 em 8 dias, SEDEX R$ 30 em 3.
 */
class FreteRegioesTest extends TestCase
{
    use RefreshDatabase;

    private const CEP_FORTALEZA = '60025000';

    private const CEP_CAUCAIA = '61600000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => true, 'frete_embutido.regioes_ativo' => true]);

        DB::table('ibge_estados')->insert([['id' => 23, 'sigla' => 'CE', 'nome' => 'Ceará'], ['id' => 35, 'sigla' => 'SP', 'nome' => 'São Paulo']]);
        DB::table('ibge_municipios')->insert([
            ['id' => 2304400, 'nome' => 'Fortaleza', 'nome_normalizado' => 'fortaleza', 'estado_id' => 23, 'uf' => 'CE'],
            ['id' => 2303709, 'nome' => 'Caucaia', 'nome_normalizado' => 'caucaia', 'estado_id' => 23, 'uf' => 'CE'],
        ]);

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

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'viacep.com.br')) {
                $ibge = str_contains($request->url(), self::CEP_CAUCAIA) ? '2303709' : '2304400';

                return Http::response(['cep' => '00000-000', 'uf' => 'CE', 'localidade' => 'x', 'ibge' => $ibge]);
            }
            if (! str_contains($request->url(), 'correios.test')) {
                return null;
            }
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => $p['coProduto'] === '03220' ? '30,00' : '20,00',
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                'prazoEntrega' => $p['coProduto'] === '03220' ? 3 : 8,
            ])->all());
        });
    }

    private function freteEm(string $cep): array
    {
        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep($cep);

        return $frete->paraProduto(Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]));
    }

    private function regra(array $dados, array $locais): FreteRegiao
    {
        $regra = FreteRegiao::create(['nome' => 'Teste', ...$dados]);
        foreach ($locais as $local) {
            $regra->locais()->create($local);
        }

        return $regra;
    }

    public function test_sem_regra_fica_a_cotacao_dos_correios(): void
    {
        $f = $this->freteEm(self::CEP_FORTALEZA);

        $this->assertSame(20.0, $f['embutido']);
        $this->assertSame(30.0, $f['sedex']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'viacep'));
    }

    public function test_valor_fixo_por_municipio_e_prazo_proprio(): void
    {
        $this->regra(['tipo_preco' => 'fixo', 'valor' => 12, 'prazo_dias' => 4], [['municipio_id' => 2304400]]);

        $f = $this->freteEm(self::CEP_FORTALEZA);

        $this->assertSame(12.0, $f['embutido']);
        $this->assertSame(12.0, $f['sedex']);
        $this->assertSame(4, $f['prazo']);
    }

    public function test_regra_de_outro_municipio_nao_vale(): void
    {
        $this->regra(['tipo_preco' => 'fixo', 'valor' => 12], [['municipio_id' => 2303709]]);

        $this->assertSame(20.0, $this->freteEm(self::CEP_FORTALEZA)['embutido']);
    }

    public function test_acrescimo_percentual_por_uf_e_teto_so_no_sedex(): void
    {
        $this->regra(['tipo_preco' => 'acrescimo', 'valor' => 10, 'percentual' => true], [['uf' => 'ce']]);
        $this->regra(['tipo_preco' => 'teto', 'valor' => 25, 'servico' => 'sedex'], [['municipio_id' => 2304400]]);

        $f = $this->freteEm(self::CEP_FORTALEZA);

        $this->assertSame(22.0, $f['pac']);   // UF: 20 + 10%
        $this->assertSame(25.0, $f['sedex']); // município só no SEDEX: teto 25
    }

    public function test_faixa_de_cep_ganha_do_municipio_e_desconto_nao_fica_negativo(): void
    {
        $this->regra(['tipo_preco' => 'fixo', 'valor' => 12], [['municipio_id' => 2304400]]);
        $this->regra(['tipo_preco' => 'desconto', 'valor' => 50], [['cep_inicio' => '60000-000', 'cep_fim' => '60099-999']]);

        $f = $this->freteEm(self::CEP_FORTALEZA);

        $this->assertSame(0.0, $f['pac']);
        $this->assertSame(0.0, $f['sedex']);
    }

    public function test_so_faixa_de_cep_nao_consulta_o_viacep(): void
    {
        $this->regra(['tipo_preco' => 'acrescimo', 'valor' => 5], [['cep_inicio' => '60025000']]);

        $this->assertSame(25.0, $this->freteEm(self::CEP_FORTALEZA)['embutido']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'viacep'));
    }

    public function test_peso_fora_da_faixa_inativa_e_chave_desligada_nao_aplicam(): void
    {
        $this->regra(['tipo_preco' => 'fixo', 'valor' => 1, 'peso_min_g' => 5001], [['uf' => 'CE']]);
        $this->regra(['tipo_preco' => 'fixo', 'valor' => 2, 'ativo' => false], [['uf' => 'CE']]);
        $this->assertSame(20.0, $this->freteEm(self::CEP_FORTALEZA)['embutido']);

        $this->regra(['tipo_preco' => 'fixo', 'valor' => 3], [['uf' => 'CE']]);
        config(['frete_embutido.regioes_ativo' => false]);
        app()->forgetScopedInstances();
        $this->assertSame(20.0, $this->freteEm(self::CEP_FORTALEZA)['embutido']);
    }

    public function test_maior_prioridade_ganha_no_mesmo_nivel_e_cache_limpa_ao_salvar(): void
    {
        $this->regra(['tipo_preco' => 'fixo', 'valor' => 9, 'prioridade' => 1], [['uf' => 'CE']]);
        $alta = $this->regra(['tipo_preco' => 'fixo', 'valor' => 7, 'prioridade' => 5], [['uf' => 'CE']]);

        $this->assertSame(7.0, app(FreteRegioes::class)->regra(self::CEP_CAUCAIA, 1000)['valor']);

        $alta->update(['ativo' => false]);
        $this->assertSame(9.0, app(FreteRegioes::class)->regra(self::CEP_CAUCAIA, 1000)['valor']);
    }

    public function test_carrinho_usa_a_regra_na_caixa(): void
    {
        $this->regra(['tipo_preco' => 'fixo', 'valor' => 15], [['municipio_id' => 2304400]]);
        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep(self::CEP_FORTALEZA);
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);

        $entrega = $frete->entregaDoCarrinho(collect([['product' => $produto, 'quantity' => 2]]));

        $this->assertSame(15.0, $entrega['custo']);
        $this->assertSame(15.0, $entrega['desconto_plataforma']); // 2 x 15 embutido − 15 da caixa
    }

    public function test_comando_mostra_a_regra(): void
    {
        $this->regra(['nome' => 'Fortaleza fixo', 'tipo_preco' => 'fixo', 'valor' => 12], [['municipio_id' => 2304400]]);

        $this->artisan('frete:regiao', ['cep' => '60025-000'])
            ->expectsOutputToContain('Fortaleza/CE (IBGE 2304400)')
            ->expectsOutputToContain('"Fortaleza fixo"')
            ->assertSuccessful();
    }
}
