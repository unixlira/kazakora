<?php

namespace Tests\Feature\Infoprodutos;

use App\Console\Commands\InfoprodutosConsolidar;
use App\Models\User;
use App\Modules\Infoprodutos\Models\InfoprodutoCarrinho;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Models\InfoprodutoVenda;
use App\Modules\Infoprodutos\Support\Comportamento;
use App\Modules\Infoprodutos\Support\FunilInfoprodutos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Agregação do funil, consolidação diária e leituras do mapa de cliques. */
class FunilInfoprodutosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.testing.ensure_pages_exist' => false]);
        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function evento(string $sessao, string $tipo, array $extra = []): void
    {
        InfoprodutoEvento::create(array_merge([
            'sessao' => $sessao, 'produto' => 'pidelo-bien', 'pagina' => 'landing', 'idioma' => 'es', 'tipo' => $tipo,
            'created_at' => now()->subHour(),
        ], $extra));
    }

    /** 4 visitas: 3 rolam 50%, 2 dão play (1 até o fim), 2 clicam em comprar; 1 compra + 1 bump + 1 upsell. */
    private function cenario(): void
    {
        foreach (['s1', 's2', 's3', 's4'] as $i => $s) {
            $this->evento($s.'xxxxxxxxxxxxxxxx', 'pageview', ['utm_content' => $i < 2 ? 'ad-1' : null, 'utm_source' => $i < 2 ? 'facebook' : null, 'pais' => $i ? 'MX' : 'CO', 'dispositivo' => 'celular']);
        }
        foreach (['s1', 's2', 's3'] as $s) {
            $this->evento($s.'xxxxxxxxxxxxxxxx', 'scroll', ['valor' => '50']);
        }
        $this->evento('s1xxxxxxxxxxxxxxxx', 'play');
        $this->evento('s1xxxxxxxxxxxxxxxx', 'video', ['valor' => '100']);
        $this->evento('s2xxxxxxxxxxxxxxxx', 'play');
        $this->evento('s1xxxxxxxxxxxxxxxx', 'buy', ['valor' => 'hero']);
        $this->evento('s2xxxxxxxxxxxxxxxx', 'buy', ['valor' => 'barra']);
        $this->evento('s1xxxxxxxxxxxxxxxx', 'exit', ['valor' => '60']);
        // Visita de teste fica fora.
        $this->evento('tsxxxxxxxxxxxxxxxx', 'pageview', ['teste' => true]);

        $venda = fn (string $id, string $tipo, float $valor, string $status = 'aprovada') => InfoprodutoVenda::create([
            'kiwify_order_id' => $id, 'produto' => 'pidelo-bien', 'tipo' => $tipo, 'status' => $status, 'valor' => $valor, 'moeda' => 'USD',
            'utm_content' => 'ad-1', 'src' => 'hero', 'pais' => 'CO', 'aprovada_em' => $status === 'pendente' ? null : now()->subMinutes(30),
        ]);
        $venda('o1', 'principal', 7);
        $venda('o2', 'order_bump', 5);
        $venda('o3', 'upsell', 17);
        $venda('o4', 'principal', 7, 'pendente');
        InfoprodutoCarrinho::create(['kiwify_id' => 'c1', 'produto' => 'pidelo-bien', 'criado_em' => now()->subMinutes(40)]);
    }

    public function test_funil_por_visita_com_taxas(): void
    {
        $this->cenario();
        [$de, $ate] = FunilInfoprodutos::intervalo('hoje');
        $f = app(FunilInfoprodutos::class)->montar('pidelo-bien', $de, $ate, ['ad-1' => ['gasto' => 10.0, 'nome' => 'Vídeo 1']], 'USD');

        $n = collect($f['passos'])->pluck('n', 'chave')->all();
        $this->assertSame(['visitas' => 4, 'rolou_50' => 3, 'play' => 2, 'comprar' => 2, 'checkout' => 3, 'compra' => 1, 'order_bump' => 1, 'upsell' => 1, 'downsell' => 0], $n);

        $passo = collect($f['passos'])->keyBy('chave');
        $this->assertSame(75.0, $passo['rolou_50']['taxa_passo']);
        $this->assertSame(1, $passo['rolou_50']['saiu']);
        $this->assertSame(100.0, $passo['upsell']['taxa_passo']); // sobre as compras
        $this->assertSame(25.0, $passo['compra']['taxa_visitas']);

        $this->assertSame(50.0, $f['video']['ate_o_fim']);
        $this->assertSame(['USD' => 29.0], $f['receita']['por_moeda']);

        $anuncio = collect($f['anuncios'])->firstWhere('utm_content', 'ad-1');
        $this->assertSame([2, 1, 10.0, 5.0, 10.0, 2.9], [$anuncio['visitas'], $anuncio['compras'], $anuncio['custo_compra'], $anuncio['custo_visita'], $anuncio['gasto'], $anuncio['roas']]);

        $hero = collect($f['botoes'])->firstWhere('posicao', 'hero');
        $this->assertSame([1, 1, 100.0], [$hero['cliques'], $hero['compras'], $hero['conversao']]);
        $this->assertSame(60, $f['tempo_medio']);
    }

    public function test_consolidacao_diaria_e_limpeza_dos_90_dias(): void
    {
        $this->cenario();
        InfoprodutoEvento::query()->update(['created_at' => now()->subDay()->setTime(12, 0)]);
        $this->evento('velhoxxxxxxxxxxxxx', 'pageview', ['created_at' => now()->subDays(91)]);

        $this->artisan('infoprodutos:consolidar', ['--dias' => 1])->assertSuccessful();

        $linhas = DB::table('infoproduto_diarios')->get();
        $this->assertSame(4, (int) $linhas->sum('visitas'));
        $this->assertSame(3, (int) $linhas->sum('rolou_50'));
        $this->assertSame(1, (int) $linhas->sum('video_fim'));
        $this->assertSame(2, (int) $linhas->where('utm_content', 'ad-1')->sum('visitas'));
        $this->assertSame(0, InfoprodutoEvento::where('sessao', 'velhoxxxxxxxxxxxxx')->count());

        // Refazer o dia não duplica.
        InfoprodutosConsolidar::consolidarDia(now()->subDay());
        $this->assertSame(4, (int) DB::table('infoproduto_diarios')->sum('visitas'));
    }

    public function test_mapa_de_cliques_rolagem_sequencia_e_visita(): void
    {
        $base = ['produto' => 'kz/arvore', 'pagina' => 'produto'];
        $this->evento('k1xxxxxxxxxxxxxxxx', 'pageview', $base + ['dispositivo' => 'celular']);
        $this->evento('k2xxxxxxxxxxxxxxxx', 'pageview', $base + ['dispositivo' => 'celular']);
        $this->evento('k1xxxxxxxxxxxxxxxx', 'clk', $base + ['x' => 50, 'y' => 400, 'largura' => 390, 'alvo' => 'b', 'nome' => 'galeria:1', 'ms' => 1000]);
        $this->evento('k1xxxxxxxxxxxxxxxx', 'clk', $base + ['x' => 50, 'y' => 1200, 'largura' => 390, 'alvo' => 'b', 'nome' => 'comprar', 'ms' => 4000]);
        $this->evento('k2xxxxxxxxxxxxxxxx', 'clk', $base + ['x' => 50, 'y' => 1203, 'largura' => 390, 'alvo' => 'b', 'nome' => 'comprar', 'ms' => 3000]);
        $this->evento('k2xxxxxxxxxxxxxxxx', 'clk', $base + ['x' => 20, 'y' => 700, 'largura' => 390, 'alvo' => 'm', 'secao' => 'beneficios', 'elemento' => 'Frete grátis', 'ms' => 2000]);
        $this->evento('k1xxxxxxxxxxxxxxxx', 'exit', $base + ['valor' => '30', 'rolagem_max' => 90, 'ms' => 30000]);
        $this->evento('k2xxxxxxxxxxxxxxxx', 'exit', $base + ['valor' => '10', 'rolagem_max' => 40, 'ms' => 10000]);

        [$de, $ate] = FunilInfoprodutos::intervalo('7d');
        $m = app(Comportamento::class)->montar('kz/arvore', 'produto', Comportamento::CELULAR, $de, $ate);

        $this->assertSame(2, $m['visitas']);
        $comprar = collect($m['onde_clicam'])->firstWhere('elemento', 'clicou em Comprar');
        $this->assertSame([2, 100.0], [$comprar['visitas'], $comprar['pct']]);
        $this->assertSame('clique morto em "Frete grátis" (seção beneficios)', $m['cliques_mortos'][0]['elemento']);
        $this->assertSame(2, collect($m['pontos'])->firstWhere('y', 1205)['n']); // y 1200 e 1203 na mesma célula
        $this->assertSame(50.0, collect($m['rolagem'])->firstWhere('pct_pagina', 50)['pct_visitas']);
        $this->assertContains('entrou → 1ª imagem da galeria → Comprar → saiu', collect($m['sequencias'])->pluck('sequencia')->all());
        $this->assertSame(20, $m['tempo_medio']);

        $linha = app(Comportamento::class)->visita('k1xxxxxxxxxxxxxxxx', 'kz/arvore');
        $this->assertSame(['entrou na página', 'clicou na 1ª imagem da galeria', 'clicou em Comprar', 'saiu depois de 30 s (viu 90% da página)'], array_column($linha, 'texto'));
        $this->assertSame(3.0, $linha[2]['desde_anterior']);
    }

    public function test_telas_abrem_para_quem_tem_permissao(): void
    {
        $this->cenario();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/publicidade/infoprodutos?periodo=hoje&produto=pidelo-bien')->assertOk();
        $this->actingAs($admin)->get('/admin/publicidade/comportamento?loja=infoprodutos')->assertOk();
        $this->actingAs($admin)->getJson('/admin/publicidade/comportamento/visita?sessao=s1xxxxxxxxxxxxxxxx&produto=pidelo-bien')
            ->assertOk()->assertJsonPath('passos.0.texto', 'entrou na página');
        $this->get('/admin/publicidade/infoprodutos')->assertRedirect();
    }
}
