<?php

namespace Tests\Feature\Oportunidades;

use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosTikTok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Radar de Oportunidades (Lira 2026-10-12): o TikTok traz o ranking inteiro
 * do Kalodata (páginas de 100), não só os 20 primeiros, guardado em arquivo.
 */
class MaisVendidosTikTokTest extends TestCase
{
    use RefreshDatabase;

    private const RANK = 'https://www.kalodata.com/openapi/v1/tiktok/product/rank';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kalodata.api_key' => 'chave-teste',
            'services.kalodata.base_url' => 'https://www.kalodata.com/openapi/v1',
            'services.kalodata.teto_diario' => 20,
            'services.kalodata.espera_ms' => 0,
        ]);
        Storage::fake('local');
    }

    private static function pagina(int $inicio, int $quantos): array
    {
        $itens = [];
        for ($i = $inicio; $i < $inicio + $quantos; $i++) {
            $itens[] = ['product_id' => (string) $i, 'product_name' => "Produto {$i}", 'revenue' => 1000 - $i];
        }

        return ['success' => true, 'code' => 200, 'data' => ['data' => $itens]];
    }

    public function test_busca_paginas_de_100_ate_a_pagina_incompleta(): void
    {
        Http::fake([self::RANK => Http::sequence()
            ->push(self::pagina(1, 100))
            ->push(self::pagina(101, 100))
            ->push(self::pagina(201, 37))]);

        $resultado = app(MaisVendidosTikTok::class)->analisar();

        $this->assertNull($resultado['erro']);
        $this->assertCount(237, $resultado['produtos']);
        $this->assertSame(237, $resultado['produtos'][236]['posicao']);
        $this->assertSame('Produto 237', $resultado['produtos'][236]['nome']);
        Http::assertSentCount(3);
        foreach ([1, 2, 3] as $n) {
            Http::assertSent(fn (Request $r) => $r['page_number'] === $n && $r['page_size'] === 100 && $r['date_range'] === 'last7Day');
        }

        // Segunda abertura do Radar no mesmo dia, mesmo depois de um deploy
        // limpar o cache (optimize:clear): nenhuma chamada nova.
        Artisan::call('cache:clear');
        $this->assertCount(237, app(MaisVendidosTikTok::class)->analisar()['produtos']);
        Http::assertSentCount(3);
    }

    public function test_falha_no_dia_seguinte_mostra_o_ultimo_ranking(): void
    {
        Http::fake([self::RANK => Http::sequence()
            ->push(self::pagina(1, 40))
            ->push(['success' => false, 'message' => 'quota exceeded', 'code' => '2016'])]);

        app(MaisVendidosTikTok::class)->analisar();
        $this->travel(25)->hours();
        $resultado = app(MaisVendidosTikTok::class)->analisar();

        Http::assertSentCount(2);
        $this->assertNull($resultado['erro']);
        $this->assertCount(40, $resultado['produtos']);
    }

    public function test_para_no_limite_de_paginas_do_radar(): void
    {
        config(['services.kalodata.radar_paginas' => 3]);
        Http::fake([self::RANK => Http::sequence()
            ->push(self::pagina(1, 100))->push(self::pagina(101, 100))->push(self::pagina(201, 100))
            ->push(self::pagina(301, 100))]);

        $this->assertCount(300, app(MaisVendidosTikTok::class)->analisar()['produtos']);
        Http::assertSentCount(3);
    }

    public function test_produto_repetido_entre_paginas_entra_uma_vez(): void
    {
        Http::fake([self::RANK => Http::sequence()
            ->push(self::pagina(1, 100))
            ->push(self::pagina(100, 10))]);

        $produtos = app(MaisVendidosTikTok::class)->analisar()['produtos'];

        $this->assertCount(109, $produtos);
        $this->assertSame(range(1, 109), array_column($produtos, 'posicao'));
    }

    public function test_erro_numa_pagina_seguinte_mantem_o_que_veio(): void
    {
        Http::fake([self::RANK => Http::sequence()
            ->push(self::pagina(1, 100))
            ->push(['success' => false, 'message' => 'quota exceeded', 'code' => '2016'])]);

        $resultado = app(MaisVendidosTikTok::class)->analisar();

        $this->assertNull($resultado['erro']);
        $this->assertCount(100, $resultado['produtos']);
    }

    public function test_erro_na_primeira_pagina_vira_aviso(): void
    {
        Http::fake([self::RANK => Http::response(['success' => false, 'message' => 'quota exceeded', 'code' => '2016'])]);

        $resultado = app(MaisVendidosTikTok::class)->analisar();

        $this->assertSame([], $resultado['produtos']);
        $this->assertStringContainsString('crédito', $resultado['erro']);
    }
}
