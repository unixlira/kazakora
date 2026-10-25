<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteRegiao;
use App\Modules\Checkout\Support\RegrasDeFrete;
use App\Modules\Fiscal\Models\Company;
use App\Support\SiteAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frete por distrito (pedido 2026-10-25): Afonso Cláudio (ES) tem o CEP
 * geral 29600-000 e os distritos têm CEP próprio (Serra Pelada 29603-000,
 * Pontões 29604-000, Piracema 29607-000). Cada um pode ter a sua regra.
 */
class FretesPorDistritoTest extends TestCase
{
    use RefreshDatabase;

    private const AFONSO_CLAUDIO = 3200102;

    private const VITORIA = 3205309;

    /** Respostas do ViaCEP por CEP (como o ViaCEP devolve: distrito em "bairro"). */
    private const VIACEP = [
        '29600000' => ['bairro' => '', 'localidade' => 'Afonso Cláudio', 'ibge' => '3200102'],
        '29603000' => ['bairro' => 'Serra Pelada', 'localidade' => 'Afonso Cláudio', 'ibge' => '3200102'],
        '29604000' => ['bairro' => 'Pontões', 'localidade' => 'Afonso Cláudio', 'ibge' => '3200102'],
        '29607000' => ['bairro' => 'Piracema', 'localidade' => 'Afonso Cláudio', 'ibge' => '3200102'],
        '29609000' => ['bairro' => 'Fazenda Guandu', 'localidade' => 'Afonso Cláudio', 'ibge' => '3200102'],
        '29010000' => ['bairro' => 'Centro', 'localidade' => 'Vitória', 'ibge' => '3205309'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => true]);
        // Aqui o mesmo visitante consulta vários CEPs: sem o preço fixo da
        // navegação (#1206) cada CEP mostra o preço da sua regra.
        config(['frete_embutido.fixo_na_navegacao' => false]);

        $agora = now();
        DB::table('ibge_estados')->insert(['id' => 32, 'sigla' => 'ES', 'nome' => 'Espírito Santo', 'created_at' => $agora, 'updated_at' => $agora]);
        DB::table('ibge_municipios')->insert([
            ['id' => self::AFONSO_CLAUDIO, 'nome' => 'Afonso Cláudio', 'nome_normalizado' => 'afonso claudio', 'estado_id' => 32, 'uf' => 'ES', 'created_at' => $agora, 'updated_at' => $agora],
            ['id' => self::VITORIA, 'nome' => 'Vitória', 'nome_normalizado' => 'vitoria', 'estado_id' => 32, 'uf' => 'ES', 'created_at' => $agora, 'updated_at' => $agora],
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

        // PAC R$ 25,00 (prazo 6) e SEDEX R$ 40,00 (prazo 3): o PAC vai embutido.
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'servicodados.ibge.gov.br')) {
                return Http::response([
                    ['id' => 320010205, 'nome' => 'Afonso Cláudio'],
                    ['id' => 320010230, 'nome' => 'Piracema'],
                    ['id' => 320010235, 'nome' => 'Pontões'],
                    ['id' => 320010240, 'nome' => 'Serra Pelada'],
                    ['id' => 320010215, 'nome' => 'Ibicaba'],
                ]);
            }
            if (preg_match('#viacep\.com\.br/ws/ES/[^/]+/centro/json#i', $url)) {
                return Http::response([['cep' => '29600-000', 'logradouro' => '', 'bairro' => '', 'localidade' => 'Afonso Cláudio', 'uf' => 'ES', 'ibge' => '3200102']]);
            }
            if (preg_match('#viacep\.com\.br/ws/(\d{8})/json#', $url, $m)) {
                return isset(self::VIACEP[$m[1]])
                    ? Http::response(['cep' => substr($m[1], 0, 5).'-'.substr($m[1], 5), 'logradouro' => '', 'uf' => 'ES', ...self::VIACEP[$m[1]]])
                    : Http::response(['erro' => 'true']);
            }
            if (str_contains($url, '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'pcFinal' => $p['coProduto'] === '03220' ? '40,00' : '25,00',
                ])->all());
            }
            if (str_contains($url, '/prazo/')) {
                return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'prazoEntrega' => $p['coProduto'] === '03220' ? 3 : 6,
                ])->all());
            }

            return Http::response([], 404);
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function regra(string $tipo, float $valor, array $local, ?int $prazo = null): FreteRegiao
    {
        $regra = FreteRegiao::create(['site' => SiteAtual::KAZAKORA, 'nome' => $local['distrito'] ?? 'Cidade', 'tipo_preco' => $tipo, 'valor' => $valor, 'prazo_dias' => $prazo]);
        $regra->locais()->create(['municipio_id' => self::AFONSO_CLAUDIO, ...$local]);
        RegrasDeFrete::esquecer();

        return $regra;
    }

    public function test_lista_os_distritos_do_ibge_com_o_cep_de_cada_um(): void
    {
        $resposta = $this->actingAs($this->admin())
            ->getJson('/admin/logistica/fretes-por-estado/cidades/'.self::AFONSO_CLAUDIO.'/distritos')
            ->assertOk()
            ->assertJsonPath('cep_geral', '29600000');

        $distritos = collect($resposta->json('distritos'))->keyBy('nome');
        // Sede (Afonso Cláudio) fica de fora; Fazenda Guandu veio só pelo CEP.
        $this->assertSame(['Fazenda Guandu', 'Ibicaba', 'Piracema', 'Pontões', 'Serra Pelada'], $distritos->keys()->all());
        $this->assertSame('29603000', $distritos['Serra Pelada']['cep_sugerido']);
        $this->assertSame(320010240, $distritos['Serra Pelada']['id']);
        $this->assertSame('29604000', $distritos['Pontões']['cep_sugerido']);
        $this->assertSame('29607000', $distritos['Piracema']['cep_sugerido']);
        $this->assertNull($distritos['Ibicaba']['cep_sugerido']);
        $this->assertNull($distritos['Fazenda Guandu']['id']);
    }

    public function test_cria_troca_e_tira_a_regra_do_distrito(): void
    {
        $admin = $this->admin();
        $url = '/admin/logistica/fretes-por-estado/cidades/'.self::AFONSO_CLAUDIO.'/distritos';

        $this->actingAs($admin)
            ->putJson($url, ['distrito' => 'Serra Pelada', 'distrito_id' => 320010240, 'cep_inicio' => '29603-000', 'tipo_preco' => 'acrescimo', 'valor' => 8, 'prazo_dias' => 9])
            ->assertOk()
            ->assertJsonPath('regra.tipo_preco', 'acrescimo')
            ->assertJsonPath('regra.prazo_dias', 9)
            ->assertJsonPath('regra.cep_inicio', '29603000')
            ->assertJsonPath('regra.cep_fim', null);

        $id = $this->actingAs($admin)
            ->putJson($url, ['distrito' => 'Serra Pelada', 'cep_inicio' => '29603000', 'tipo_preco' => 'fixo', 'valor' => 0])
            ->assertOk()
            ->json('regra.id');

        $this->assertSame(1, FreteRegiao::count());
        $this->assertDatabaseHas('frete_regioes', ['nome' => 'Serra Pelada (Afonso Cláudio - ES)', 'tipo_preco' => 'fixo', 'valor' => 0]);
        $this->assertDatabaseHas('frete_regiao_locais', ['municipio_id' => self::AFONSO_CLAUDIO, 'distrito' => 'Serra Pelada', 'cep_inicio' => '29603000', 'cep_fim' => '29603000']);

        // Distrito com regra não vira regra da cidade inteira.
        $this->actingAs($admin)->get('/admin/logistica/fretes-por-estado/cidades?uf=es')
            ->assertInertia(fn ($page) => $page
                ->where('cidades.0.regras.todas', null)
                ->where('cidades.0.distritos_com_regra', 1));

        $this->actingAs($admin)
            ->getJson($url)
            ->assertJsonPath('distritos.4.nome', 'Serra Pelada')
            ->assertJsonPath('distritos.4.regra.valor', 0);

        $this->actingAs($admin)->deleteJson("{$url}/{$id}")->assertOk();
        $this->assertSame(0, FreteRegiao::count());
    }

    public function test_recusa_cep_de_outra_cidade_e_faixa_invertida(): void
    {
        $admin = $this->admin();
        $url = '/admin/logistica/fretes-por-estado/cidades/'.self::AFONSO_CLAUDIO.'/distritos';

        $this->actingAs($admin)
            ->putJson($url, ['distrito' => 'Serra Pelada', 'cep_inicio' => '29010-000', 'tipo_preco' => 'fixo', 'valor' => 0])
            ->assertStatus(422)
            ->assertJsonPath('errors.cep_inicio.0', 'O CEP 29010-000 é de Vitória - ES, não de Afonso Cláudio.');

        $this->actingAs($admin)
            ->putJson($url, ['distrito' => 'Serra Pelada', 'cep_inicio' => '29604000', 'cep_fim' => '29603000', 'tipo_preco' => 'fixo', 'valor' => 0])
            ->assertStatus(422);

        $this->assertSame(0, FreteRegiao::count());
    }

    public function test_cada_distrito_tem_o_seu_preco_e_prazo_e_a_sede_segue_a_cidade(): void
    {
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $this->regra(FreteRegiao::FIXO, 10, []);                                                                      // cidade: R$ 10
        $this->regra(FreteRegiao::FIXO, 0, ['distrito' => 'Serra Pelada', 'cep_inicio' => '29603000', 'cep_fim' => '29603000'], 4); // grátis, 4 dias
        $this->regra(FreteRegiao::ACRESCIMO, 15, ['distrito' => 'Pontões', 'cep_inicio' => '29604000', 'cep_fim' => '29604000'], 12); // PAC + 15
        // Sem CEP: casa pelo nome que o ViaCEP devolve no bairro.
        $this->regra(FreteRegiao::TETO, 20, ['distrito' => 'Piracema']);

        $prazo = fn (string $cep) => $this->getJson("/frete/prazo?cep={$cep}&produto={$produto->id}")->assertOk();

        $prazo('29600000')->assertJsonPath('final_price', 110);
        $prazo('29603000')->assertJsonPath('final_price', 100)->assertJsonPath('prazo_gratis', 4);
        $prazo('29604000')->assertJsonPath('final_price', 140)->assertJsonPath('prazo_gratis', 12);
        $prazo('29607000')->assertJsonPath('final_price', 120);
        // Distrito sem regra própria segue a da cidade.
        $prazo('29609000')->assertJsonPath('final_price', 110);
    }
}
