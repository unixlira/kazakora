<?php

namespace Tests\Feature\Kalodata;

use App\Models\KalodataChamada;
use App\Services\Kalodata\Exceptions\KalodataException;
use App\Services\Kalodata\KalodataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class KalodataServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://www.kalodata.com/openapi/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kalodata.api_key' => 'chave-de-teste',
            'services.kalodata.base_url' => self::BASE,
            'services.kalodata.regiao' => 'BR',
            'services.kalodata.cache_horas' => 24,
            'services.kalodata.teto_diario' => 20,
            'services.kalodata.tentativas' => 3,
            'services.kalodata.espera_ms' => 0,
        ]);
    }

    private function servico(): KalodataService
    {
        return app(KalodataService::class);
    }

    public function test_chave_recusada_vira_erro_de_auth_e_fica_registrada(): void
    {
        Http::fake([
            self::BASE.'/*' => Http::response(['success' => false, 'message' => 'The key is not allowed', 'code' => '501']),
        ]);

        try {
            $this->servico()->buscarCategorias('power bank');
            $this->fail('Deveria ter lançado KalodataException.');
        } catch (KalodataException $e) {
            $this->assertSame('auth', $e->tipo);
        }

        Http::assertSentCount(1);
        $this->assertSame(1, KalodataChamada::count());
        $this->assertFalse(KalodataChamada::first()->sucesso);
        $this->assertSame('501', KalodataChamada::first()->codigo);
    }

    public function test_http_401_tambem_e_auth(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['success' => false, 'message' => 'unauthorized'], 401)]);

        $this->expectException(KalodataException::class);
        $this->expectExceptionMessage('recusada');

        $this->servico()->rank('product', ['category_ids' => [910728]]);
    }

    public function test_sem_chave_nao_chama_a_api(): void
    {
        config(['services.kalodata.api_key' => null]);
        Http::fake();

        try {
            $this->servico()->buscarCategorias('power bank');
            $this->fail('Deveria ter lançado KalodataException.');
        } catch (KalodataException $e) {
            $this->assertSame('config', $e->tipo);
        }

        Http::assertNothingSent();
        $this->assertSame(0, KalodataChamada::count());
    }

    public function test_sucesso_manda_cabecalho_e_corpo_certos(): void
    {
        Http::fake([
            self::BASE.'/tiktok/category/searchByKeyword' => Http::response([
                'success' => true,
                'data' => [['id' => 910728, 'name' => 'Power Banks']],
                'message' => null,
                'code' => '200',
            ]),
        ]);

        $categorias = $this->servico()->buscarCategorias('power bank');

        $this->assertSame([['id' => 910728, 'name' => 'Power Banks']], $categorias);
        Http::assertSent(function (Request $r) {
            return $r->url() === self::BASE.'/tiktok/category/searchByKeyword'
                && $r->method() === 'POST'
                && $r->hasHeader('secret-key', 'chave-de-teste')
                && $r['region'] === 'BR'
                && $r['keyword'] === 'power bank';
        });
        $this->assertTrue(KalodataChamada::first()->sucesso);
        $this->assertSame('/tiktok/category/searchByKeyword', KalodataChamada::first()->endpoint);
        $this->assertStringNotContainsString('chave-de-teste', (string) KalodataChamada::first()->corpo);
    }

    public function test_rank_monta_paginacao_ordem_e_aceita_data_aninhado(): void
    {
        Http::fake([
            self::BASE.'/tiktok/product/rank' => Http::response([
                'success' => true,
                'data' => ['data' => [['product_id' => '1', 'product_name' => 'Carregador']]],
            ]),
        ]);

        $produtos = $this->servico()->rank('product', ['category_ids' => [910728]], 'last7Day', 2, 20, ['field' => 'revenue']);

        $this->assertSame('Carregador', $produtos[0]['product_name']);
        Http::assertSent(fn (Request $r) => $r['page_number'] === 2
            && $r['page_size'] === 20
            && $r['date_range'] === 'last7Day'
            && $r['category_ids'] === [910728]
            && $r['sort_field'] === ['field' => 'revenue', 'type' => 'DESC']);
    }

    public function test_detalhe_usa_o_campo_de_id_de_cada_modulo(): void
    {
        Http::fake([self::BASE.'/tiktok/shop/detail' => Http::response(['success' => true, 'data' => ['shop_id' => '77']])]);

        $this->assertSame(['shop_id' => '77'], $this->servico()->detalhe('shop', 77));
        Http::assertSent(fn (Request $r) => $r['shop_id'] === '77' && $r['need_extra'] === true);
    }

    public function test_parametro_invalido_nao_chama_a_api(): void
    {
        Http::fake();

        foreach ([
            fn () => $this->servico()->rank('produto'),
            fn () => $this->servico()->rank('product', periodo: 'ontem'),
            fn () => $this->servico()->rank('product', pagina: 0),
            fn () => $this->servico()->rank('product', porPagina: 2),
            fn () => $this->servico()->buscarCategorias('x', 'AR'),
        ] as $chamada) {
            try {
                $chamada();
                $this->fail('Deveria ter lançado KalodataException.');
            } catch (KalodataException $e) {
                $this->assertSame('parametro', $e->tipo);
            }
        }

        Http::assertNothingSent();
    }

    public function test_cache_evita_chamada_repetida(): void
    {
        Http::fake([
            self::BASE.'/tiktok/category/searchByKeyword' => Http::response(['success' => true, 'data' => [['id' => 1, 'name' => 'A']]]),
        ]);

        $s = $this->servico();
        $primeira = $s->buscarCategorias('power bank');
        $segunda = $s->buscarCategorias('power bank');
        $s->buscarCategorias('fone');

        $this->assertSame($primeira, $segunda);
        Http::assertSentCount(2);
        $this->assertSame(2, KalodataChamada::count());
    }

    public function test_chave_de_cache_nao_depende_da_ordem_dos_campos(): void
    {
        $this->assertSame(
            KalodataService::chaveCache('/x', ['a' => 1, 'b' => ['d' => 2, 'c' => 3]]),
            KalodataService::chaveCache('/x', ['b' => ['c' => 3, 'd' => 2], 'a' => 1]),
        );
        $this->assertNotSame(KalodataService::chaveCache('/x', ['a' => 1]), KalodataService::chaveCache('/y', ['a' => 1]));
    }

    public function test_erro_nao_vai_para_o_cache(): void
    {
        Http::fake([
            self::BASE.'/*' => Http::sequence()
                ->push(['success' => false, 'message' => 'quota exceeded', 'code' => '2016'])
                ->push(['success' => true, 'data' => [['id' => 1, 'name' => 'A']]]),
        ]);

        try {
            $this->servico()->buscarCategorias('power bank');
            $this->fail('Deveria ter lançado KalodataException.');
        } catch (KalodataException $e) {
            $this->assertSame('limite', $e->tipo);
        }

        $this->assertSame([['id' => 1, 'name' => 'A']], $this->servico()->buscarCategorias('power bank'));
        Http::assertSentCount(2);
    }

    public function test_creditos_nunca_usa_cache_e_e_get(): void
    {
        Http::fake([
            self::BASE.'/credit/usage' => Http::response(['success' => true, 'data' => [['type' => 'API', 'remain' => 14.6, 'total' => 15, 'endTime' => 1790000000000]]]),
        ]);

        $s = $this->servico();
        $s->creditos();
        $pacotes = $s->creditos();

        $this->assertSame(14.6, $pacotes[0]['remain']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET');
    }

    public function test_code_2000_repete_e_2016_nao(): void
    {
        Http::fake([
            self::BASE.'/tiktok/category/searchByKeyword' => Http::sequence()
                ->push(['success' => false, 'message' => 'acquire connection timeout', 'code' => '2000'])
                ->push(['success' => true, 'data' => [['id' => 1, 'name' => 'A']]]),
            self::BASE.'/tiktok/product/rank' => Http::response(['success' => false, 'message' => 'page_size must be <= 100', 'code' => '2016']),
        ]);

        $this->assertSame([['id' => 1, 'name' => 'A']], $this->servico()->buscarCategorias('power bank'));
        $this->assertSame(2, KalodataChamada::where('endpoint', '/tiktok/category/searchByKeyword')->count());

        try {
            $this->servico()->rank('product');
            $this->fail('Deveria ter lançado KalodataException.');
        } catch (KalodataException $e) {
            $this->assertSame('parametro', $e->tipo);
        }
        $this->assertSame(1, KalodataChamada::where('endpoint', '/tiktok/product/rank')->count());
    }

    public function test_code_2000_desiste_depois_das_tentativas(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['success' => false, 'message' => 'busy', 'code' => '2000'])]);

        try {
            $this->servico()->buscarCategorias('power bank');
            $this->fail('Deveria ter lançado KalodataException.');
        } catch (KalodataException $e) {
            $this->assertSame('rede', $e->tipo);
        }

        Http::assertSentCount(3);
    }

    public function test_teto_diario_bloqueia_e_loga(): void
    {
        config(['services.kalodata.teto_diario' => 2]);
        Log::spy();
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

        // Chamada de ontem não conta para o teto de hoje.
        $ontem = KalodataChamada::create(['endpoint' => '/x', 'sucesso' => true]);
        $ontem->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();

        $s = $this->servico();
        $s->buscarCategorias('um');
        $s->buscarCategorias('dois');

        try {
            $s->buscarCategorias('tres');
            $this->fail('Deveria ter lançado KalodataException.');
        } catch (KalodataException $e) {
            $this->assertSame('teto', $e->tipo);
        }

        // Resposta já guardada continua vindo do cache mesmo com o teto batido.
        $this->assertSame([], $s->buscarCategorias('um'));

        Http::assertSentCount(2);
        $this->assertSame(2, $s->chamadasHoje());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'teto diário'))->once();
    }

    public function test_comando_produtos_por_palavra(): void
    {
        Http::fake([
            self::BASE.'/tiktok/category/searchByKeyword' => Http::response(['success' => true, 'data' => [['id' => 910728, 'name' => 'Power Banks']]]),
            self::BASE.'/tiktok/product/rank' => Http::response(['success' => true, 'data' => [
                ['product_id' => '1729', 'product_name' => 'Carregador portátil 20000mAh', 'revenue' => 12345.5, 'sales_volumn' => 321, 'unit_price' => 89.9, 'commission_rate' => '10%', 'revenue_growth_rate' => '12%', 'launch_date' => '2026-01-02'],
            ]]),
        ]);

        $this->artisan('kalodata:produtos', ['categoria' => 'power bank', '--limite' => 3])
            ->expectsOutputToContain('Power Banks (910728)')
            ->expectsOutputToContain('Carregador portátil 20000mAh')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/product/rank') && $r['page_size'] === 5 && $r['category_ids'] === [910728]);
    }

    public function test_comando_testar_mostra_erro_de_auth(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['success' => false, 'message' => 'The key is not allowed', 'code' => '501'])]);

        $this->artisan('kalodata:testar')
            ->expectsOutputToContain('ERRO (auth)')
            ->assertFailed();
    }

    public function test_comando_testar_ok(): void
    {
        Http::fake([
            self::BASE.'/credit/usage' => Http::response(['success' => true, 'data' => []]),
            self::BASE.'/tiktok/category/searchByKeyword' => Http::response(['success' => true, 'data' => [['id' => 910728, 'name' => 'Power Banks']]]),
        ]);

        $this->artisan('kalodata:testar')
            ->expectsOutputToContain('OK /credit/usage')
            ->expectsOutputToContain('910728  Power Banks')
            ->assertSuccessful();
    }
}
