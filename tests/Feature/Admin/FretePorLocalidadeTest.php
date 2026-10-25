<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\PreencherTabelaFreteJob;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tabela de frete por localidade (pedido 2026-10-25): localidades da API de
 * CEP dos Correios (Água Doce do Norte e os distritos), PAC/SEDEX das 4
 * categorias salvos no banco e usados no frete embutido sem chamar a API.
 */
class FretePorLocalidadeTest extends TestCase
{
    use RefreshDatabase;

    /** Localidades do ES como a API devolve (25/10/2026), resumidas. */
    private const LOCALIDADES_ES = [
        ['numero' => 18001, 'uf' => 'ES', 'localidade' => 'Água Doce do Norte', 'cep' => '29820000', 'tipo' => 'M', 'codigoIbge' => '3200169', 'faixas' => [['cepInicial' => '29820000', 'cepFinal' => '29829999', 'tipoFaixa' => 'T']]],
        ['numero' => 18731, 'uf' => 'ES', 'localidade' => 'Governador Lacerda de Aguiar', 'cep' => '29826000', 'numeroLocalidadeSuperior' => 18001, 'localidadeSuperior' => 'Água Doce do Norte', 'tipo' => 'D'],
        ['numero' => 19901, 'uf' => 'ES', 'localidade' => 'Santa Luzia do Azul', 'cep' => '29823000', 'numeroLocalidadeSuperior' => 18001, 'localidadeSuperior' => 'Água Doce do Norte', 'tipo' => 'D'],
        ['numero' => 19971, 'uf' => 'ES', 'localidade' => 'Santo Agostinho', 'cep' => '29824000', 'numeroLocalidadeSuperior' => 18001, 'localidadeSuperior' => 'Água Doce do Norte', 'tipo' => 'D'],
        ['numero' => 20401, 'uf' => 'ES', 'localidade' => 'Vila Nelita', 'cep' => '29822000', 'numeroLocalidadeSuperior' => 18001, 'localidadeSuperior' => 'Água Doce do Norte', 'tipo' => 'D'],
        ['numero' => 20481, 'uf' => 'ES', 'localidade' => 'Vitória', 'codificada' => 'S', 'tipo' => 'M', 'codigoIbge' => '3205309', 'faixas' => [['cepInicial' => '29000000', 'cepFinal' => '29099999', 'tipoFaixa' => 'T'], ['cepInicial' => '29000001', 'cepFinal' => '29099999', 'tipoFaixa' => 'C']]],
        ['numero' => 18221, 'uf' => 'ES', 'localidade' => 'Baía Nova', 'numeroLocalidadeSuperior' => 20481, 'localidadeSuperior' => 'Vitória', 'codificada' => 'I', 'tipo' => 'P'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => true]);

        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        config([
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.token_base_url' => 'https://correios.test/token',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
            'services.correios.cep_base_url' => 'https://correios.test/cep',
        ]);

        // Preço = R$ 10 por kg (PAC) e o dobro no SEDEX; prazo PAC 7, SEDEX 3.
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/token/')) {
                return Http::response(['token' => 'token-falso']);
            }
            if (str_contains($url, '/cep/v1/localidades')) {
                return Http::response(['itens' => self::LOCALIDADES_ES, 'page' => ['number' => 0, 'size' => 1000, 'totalElements' => 7, 'totalPages' => 1]]);
            }
            if (str_contains($url, '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => number_format(((int) $p['psObjeto'] / 100) * ($p['coProduto'] === TabelaFreteLocalidades::SEDEX ? 2 : 1), 2, ',', ''),
                ])->all());
            }
            if (str_contains($url, '/prazo/')) {
                return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'prazoEntrega' => $p['coProduto'] === TabelaFreteLocalidades::SEDEX ? 3 : 7,
                ])->all());
            }

            return Http::response([], 404);
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_importa_municipio_distritos_e_povoado_com_o_cep_de_cotacao(): void
    {
        $this->assertSame(7, app(TabelaFreteLocalidades::class)->importarUf('ES'));

        $santaLuzia = DB::table('frete_localidades')->where('nome', 'Santa Luzia do Azul')->first();
        $this->assertSame('D', $santaLuzia->tipo);
        $this->assertSame('29823000', $santaLuzia->cep_cotacao);
        $this->assertSame(3200169, (int) $santaLuzia->municipio_id);
        $this->assertSame('Água Doce do Norte', $santaLuzia->municipio_nome);

        // Cidade com CEP por rua: cota pelo primeiro CEP da faixa de
        // logradouros (C), não pelo 000 da faixa total (Cachoeiro, 25/10).
        $this->assertSame('29000001', DB::table('frete_localidades')->where('nome', 'Vitória')->value('cep_cotacao'));

        // Povoado sem CEP próprio: herda o do município.
        $baia = DB::table('frete_localidades')->where('nome', 'Baía Nova')->first();
        $this->assertSame('29000001', $baia->cep_cotacao);
        $this->assertTrue((bool) $baia->cep_herdado);

        $this->assertSame(3, DB::table('frete_localidade_faixas')->count());

        // Rodar de novo atualiza, não duplica.
        app(TabelaFreteLocalidades::class)->importarUf('ES');
        $this->assertSame(7, DB::table('frete_localidades')->count());
    }

    public function test_padrao_do_estado_copia_a_referencia_e_so_ela_vai_para_os_correios(): void
    {
        // Água Doce do Norte como referência do ES (em produção é Afonso Cláudio).
        config(['frete_embutido.padrao_por_uf' => ['ES' => 3200169]]);
        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');

        $this->assertSame(['ES' => '29820000'], $tabela->referencias());
        $this->assertSame(['29820000'], $tabela->cepsPendentes(100));
        $this->assertSame(1, $tabela->cotar($tabela->cepsPendentes(100)));

        // 6 CEPs de cotação x 4 categorias, todos iguais à referência.
        $this->assertSame(24, DB::table('frete_tabela')->count());
        $this->assertSame([], $tabela->cepsPendentes(100));
        $ref = DB::table('frete_tabela')->where('cep_destino', '29820000')->get()->keyBy('categoria');
        foreach (DB::table('frete_tabela')->get() as $linha) {
            foreach (['pac_preco', 'pac_prazo', 'sedex_preco', 'sedex_prazo', 'categoria_versao'] as $c) {
                $this->assertEquals($ref[$linha->categoria]->{$c}, $linha->{$c}, "{$linha->cep_destino} {$linha->categoria} {$c}");
            }
        }
        $this->assertEquals(10.0, $tabela->linha('29015210', 'pequena')['pac']); // Vitória

        // Referência com valor próprio (o fake dos Correios dá o mesmo preço a
        // todo CEP): recotação em lote de uma cidade do ES, o que desfez o
        // padrão em 25/10, traz o valor dos Correios e a cópia volta por cima.
        DB::table('frete_tabela')->where('cep_destino', '29820000')->where('categoria', 'pequena')->update(['pac_preco' => 25.94]);
        $tabela->cotar(['29000001']);
        $this->assertEquals(25.94, (float) DB::table('frete_tabela')->where('cep_destino', '29000001')->where('categoria', 'pequena')->value('pac_preco'));
        $this->assertEquals(25.94, $tabela->linha('29015210', 'pequena')['pac']);

        // Reimportar o estado também reaplica.
        DB::table('frete_tabela')->where('cep_destino', '29823000')->update(['sedex_prazo' => 1]);
        $tabela->importarUf('ES');
        $this->assertSame(3, (int) DB::table('frete_tabela')->where('cep_destino', '29823000')->where('categoria', 'media')->value('sedex_prazo'));

        // Página da cidade (Fretes por estado → cidades) mostra o valor da referência.
        DB::table('ibge_estados')->insert(['id' => 32, 'sigla' => 'ES', 'nome' => 'Espírito Santo', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ibge_municipios')->insert(['id' => 3205309, 'nome' => 'Vitória', 'nome_normalizado' => 'vitoria', 'estado_id' => 32, 'uf' => 'ES', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin())->get('/admin/logistica/fretes-por-estado/cidades/3205309')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('localidades.0.nome', 'Vitória')->where('localidades.0.regras.pequena.pac', 25.94)->where('localidades.0.regras.pequena.pac_prazo', 7));
    }

    public function test_sem_a_referencia_importada_o_estado_segue_a_cotacao_normal(): void
    {
        config(['frete_embutido.padrao_por_uf' => ['ES' => 3200102]]); // Afonso Cláudio fora do fixture
        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');

        $this->assertSame([], $tabela->referencias());
        $this->assertCount(6, $tabela->cepsPendentes(100));
        $this->assertSame(0, $tabela->aplicarPadroes());
    }

    public function test_preco_dos_correios_em_formato_brasileiro(): void
    {
        $this->assertSame(23.45, TabelaFreteLocalidades::valor('23,45'));
        $this->assertSame(1234.56, TabelaFreteLocalidades::valor('1.234,56'));
        $this->assertSame(18.4, TabelaFreteLocalidades::valor('18.4'));
    }

    public function test_cota_quatro_categorias_por_cep_e_caixa_fora_do_limite_nao_vai_para_a_api(): void
    {
        // Caixa acima de 100 cm por lado: os Correios não levam.
        DB::table('frete_categorias_pacote')->where('chave', 'extra_grande')->update(['ref_comprimento_cm' => 400]);
        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');

        // 6 CEPs de cotação distintos (Baía Nova divide o de Vitória).
        $ceps = $tabela->cepsPendentes(100);
        $this->assertCount(6, $ceps);
        $this->assertSame(6, $tabela->cotar($ceps));

        $this->assertSame(24, DB::table('frete_tabela')->count());
        $medio = DB::table('frete_tabela')->where('cep_destino', '29823000')->where('categoria', 'media')->first();
        $this->assertEquals(50.0, (float) $medio->pac_preco);
        $this->assertEquals(100.0, (float) $medio->sedex_preco);
        $this->assertSame(7, (int) $medio->pac_prazo);
        $this->assertSame(3, (int) $medio->sedex_prazo);

        $extra = DB::table('frete_tabela')->where('cep_destino', '29823000')->where('categoria', 'extra_grande')->first();
        $this->assertNull($extra->pac_preco);
        $this->assertStringContainsString('Correios não atende', $extra->erro);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/preco/') && collect($r['parametrosProduto'])->contains('comprimento', '400'));

        $this->assertSame([], $tabela->cepsPendentes(100));
    }

    public function test_cep_do_cliente_acha_o_distrito_ou_a_faixa_do_municipio(): void
    {
        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');
        $tabela->cotar($tabela->cepsPendentes(100));

        $this->assertSame('Santa Luzia do Azul', $tabela->paraCep('29823-000')['localidade']['nome']);
        $this->assertSame('Água Doce do Norte', $tabela->paraCep('29820500')['localidade']['nome']);
        $this->assertSame('Vitória', $tabela->paraCep('29015210')['localidade']['nome']);
        $this->assertEquals(10.0, $tabela->linha('29015210', 'pequena')['pac']);
        $this->assertNull($tabela->paraCep('01001000'));
        // Escada de 7 m fechada (95 x 48 x 10 cm, 17 kg) cabe nos Correios.
        $this->assertEquals(170.0, $tabela->linha('29823000', 'extra_grande')['pac']);
    }

    public function test_frete_embutido_usa_a_tabela_salva_sem_chamar_os_correios(): void
    {
        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');
        $tabela->cotar($tabela->cepsPendentes(100));

        $pai = Product::factory()->create(['categoria_frete' => 'media']);
        $variacao = Product::factory()->create(['parent_product_id' => $pai->id]);
        FreteEmbutido::esquecerCategorias();

        Http::fake(fn () => Http::response([], 500)); // Correios fora do ar: não pode fazer falta.

        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep('29823000');

        $f = $frete->paraProduto($variacao);
        $this->assertSame('media', $f['tabela']);
        // A categoria escolhida vale também para as regras por cidade.
        $this->assertSame('media', $frete->categoria($variacao));
        $this->assertSame('pac', $f['servico']);
        $this->assertEquals(50.0, $f['embutido']);
        $this->assertSame(7, $f['prazo']);
        $this->assertFalse($f['estimado']);

        // Carrinho todo na tabela: soma item a item, TURBO pela diferença do SEDEX.
        $entrega = $frete->entregaDoCarrinho(collect([['product' => $variacao, 'quantity' => 1]]));
        $this->assertSame(7, $entrega['prazo']);
        $this->assertEquals(50.0, $entrega['custo']);
        $this->assertEquals(0.0, $entrega['desconto_plataforma']);
        $this->assertEquals(50.0, $entrega['turbo']['preco']);
        $this->assertSame(4, $entrega['turbo']['dias_a_menos']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/preco/') || str_contains($r->url(), '/prazo/'));
    }

    public function test_produto_sem_categoria_continua_na_cotacao_por_peso(): void
    {
        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');
        $tabela->cotar($tabela->cepsPendentes(100));

        $produto = Product::factory()->create(['categoria_frete' => null]);
        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep('29823000');

        $this->assertArrayNotHasKey('tabela', $frete->paraProduto($produto));
    }

    public function test_job_em_segundo_plano_preenche_tudo_e_para(): void
    {
        PreencherTabelaFreteJob::iniciar(['ES']);

        $this->assertSame(7, DB::table('frete_localidades')->count());
        $this->assertSame(24, DB::table('frete_tabela')->count());
        $status = PreencherTabelaFreteJob::status();
        $this->assertSame('parado', $status['fase']);
        $this->assertSame(6, $status['cotados']);
        $this->assertNull($status['erro']);
    }

    public function test_tela_classifica_produto_muda_categoria_e_busca_localidade(): void
    {
        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');
        $tabela->cotar($tabela->cepsPendentes(100));
        $admin = $this->admin();
        $produto = Product::factory()->create();

        $this->actingAs($admin)->get('/admin/logistica/frete-por-localidade')->assertOk();

        $this->actingAs($admin)->putJson("/admin/logistica/frete-por-localidade/produtos/{$produto->id}", ['categoria' => 'grande'])
            ->assertOk()->assertJsonPath('categoria', 'grande');
        $this->assertSame('grande', $produto->fresh()->categoria_frete);
        $this->actingAs($admin)->putJson("/admin/logistica/frete-por-localidade/produtos/{$produto->id}", ['categoria' => 'gigante'])->assertStatus(422);

        // Busca pelo nome traz o município com os 4 distritos e as regras.
        $resposta = $this->actingAs($admin)->getJson('/admin/logistica/frete-por-localidade/buscar?q=agua doce')->assertOk();
        $this->assertCount(5, $resposta->json('localidades'));
        $this->assertEquals(10.0, $resposta->json('localidades.0.regras.pequena.pac'));

        // Mudar a caixa do Médio deixa a categoria vencida e recomeça a cotação.
        $this->actingAs($admin)->putJson('/admin/logistica/frete-por-localidade/categorias/media', [
            'peso_g' => 4000, 'comprimento_cm' => 40, 'largura_cm' => 30, 'altura_cm' => 25,
        ])->assertOk();
        $this->assertEquals(40.0, (float) DB::table('frete_tabela')->where('cep_destino', '29823000')->where('categoria', 'media')->value('pac_preco'));
    }
}
