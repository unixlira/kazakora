<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\KzCriativo;
use App\Modules\Admin\Models\KzOportunidade;
use App\Modules\Admin\Support\TermometroOportunidade;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/**
 * Oportunidades › KazaKora (Lira, 2026-11-18): produtos físicos escalando,
 * termômetro e fábrica de criativos.
 */
class OportunidadesKazakoraTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->pasta = sys_get_temp_dir().'/kz-oport-teste-'.uniqid();
        mkdir($this->pasta.'/01', 0777, true);
        file_put_contents($this->pasta.'/01/111.mp4', 'video-fake');
        file_put_contents($this->pasta.'/01/111.jpg', 'poster-fake');
    }

    private function importar(): KzOportunidade
    {
        $arquivo = $this->pasta.'/ofertas.json';
        file_put_contents($arquivo, json_encode([
            'gerado_em' => '2026-11-18T10:00:00Z',
            'ofertas' => [[
                'chave' => 'loja.com/mop-giratorio',
                'nome' => 'Mop giratório 360',
                'nicho' => 'Limpeza',
                'anunciante' => 'Casa Fácil',
                'url_destino' => 'https://loja.com/mop-giratorio',
                'dominio' => 'loja.com',
                'preco' => 89.9,
                'moeda' => 'BRL',
                'paises' => ['BR'],
                'anuncios_ativos' => 40,
                'variacoes' => 14,
                'dias_rodando' => 75,
                'anunciantes_distintos' => 3,
                'anuncios' => [
                    ['id' => '111', 'texto' => 'Limpa o chão em 2 minutos', 'titulo' => 'Mop 360', 'iniciado_em' => '2026-11-15', 'variacoes' => 8,
                        'midias' => [['tipo' => 'video', 'arquivo' => '01/111.mp4', 'poster' => '01/111.jpg']]],
                    ['id' => '222', 'texto' => 'Seu chão brilhando', 'iniciado_em' => '2026-11-14', 'variacoes' => 3],
                    ['id' => '333', 'texto' => 'Chega de esfregão', 'iniciado_em' => '2026-11-12', 'variacoes' => 3],
                    ['id' => '444', 'texto' => 'Mop antigo', 'iniciado_em' => '2026-09-01', 'variacoes' => 1],
                ],
            ]],
        ]));
        $this->artisan('oportunidades-kazakora:importar', ['arquivo' => $arquivo])->assertSuccessful();

        return KzOportunidade::firstOrFail();
    }

    private function roteiro(string $titulo): array
    {
        return [
            'titulo' => $titulo, 'conceito' => 'Chão limpo sem dor nas costas', 'angulo' => 'cansaço', 'publico' => 'mães', 'formato' => 'demonstração',
            'duracao_s' => 25, 'proporcao' => '9:16',
            'gancho' => ['fala' => 'Você ainda esfrega o chão de joelho?', 'texto_tela' => 'PARE DE SOFRER', 'visual' => 'mop girando'],
            'ganchos_alternativos' => [['fala' => 'a', 'texto_tela' => 'b', 'visual' => 'c']],
            'roteiro' => [['de_s' => 0, 'ate_s' => 3, 'etapa' => 'gancho', 'visual' => 'mop', 'fala' => 'x', 'texto_tela' => 'y']],
            'retencao' => ['corte a cada 2 s'], 'cta' => ['fala' => 'Toque em comprar', 'texto_tela' => 'FRETE GRÁTIS'],
            'legenda' => ['estilo' => 'branca', 'posicao' => 'meio', 'palavras_por_tela' => 3],
            'narracao' => ['voz' => 'feminina 35', 'tom' => 'animado', 'velocidade' => 'normal', 'texto' => 'Você ainda esfrega...'],
            'musica' => ['estilo' => 'pop', 'bpm' => 110, 'volume' => 'baixo', 'onde_buscar' => 'biblioteca da Meta'],
            'texto_anuncio' => ['principal' => 'Chão limpo', 'titulo' => 'Mop', 'descricao' => 'Frete grátis'],
            'takes_necessarios' => ['mop girando no balde'], 'checklist' => ['sem preço'],
        ];
    }

    private function gemini(array ...$respostas): void
    {
        $mock = Mockery::mock(GeminiClient::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('generate')->times(count($respostas))->andReturn(...array_map(fn ($r) => '```json'."\n".json_encode($r)."\n```", $respostas));
        $this->app->instance(GeminiClient::class, $mock);
    }

    public function test_termometro_segue_os_pesos_do_plano(): void
    {
        $cheio = TermometroOportunidade::medir(60, 10, 10_000_000, 3, 3);
        $this->assertSame(100.0, $cheio['pontuacao']);
        $this->assertSame('escala', $cheio['fase']);

        // Sem alcance (fora da UE) o peso é redistribuído: tudo cheio continua 100.
        $this->assertSame(100.0, TermometroOportunidade::medir(60, 10, null, 3, 3)['pontuacao']);
        $this->assertSame('saturando', TermometroOportunidade::medir(90, 12, null, 0, 1)['fase']);
        $this->assertSame('validando', TermometroOportunidade::medir(20, 4, null, 0, 1)['fase']);
        $this->assertSame('teste', TermometroOportunidade::medir(3, 1, null, 1, 1)['fase']);
    }

    public function test_importa_e_calcula_criativos_novos_e_fase(): void
    {
        $o = $this->importar();

        $this->assertSame(3, $o->criativos_novos_7d);
        $this->assertSame('escala', $o->fase);
        $this->assertGreaterThan(80, (float) $o->pontuacao);
        $this->assertSame(4, $o->anuncios()->count());
        Storage::disk('public')->assertExists($o->miniatura);
    }

    public function test_telas_abrem(): void
    {
        $o = $this->importar();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/oportunidades/kazakora')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/OportunidadesKazakora/Index', false)
                ->has('oportunidades', 1)
                ->where('oportunidades.0.fase', 'escala')
                ->has('fases.escala'));

        $this->actingAs($admin)->get('/admin/oportunidades/kazakora/'.$o->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/OportunidadesKazakora/Show', false)
                ->has('anuncios', 4)
                ->where('oportunidade.criativosNovos7d', 3));
    }

    public function test_clonar_cria_criativo_com_codigo_pasta_referencia_e_roteiro(): void
    {
        $o = $this->importar();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->gemini([$this->roteiro('Clone do mop')]);
        $anuncio = $o->anuncios()->where('ad_archive_id', '111')->firstOrFail();

        $this->actingAs($admin)->post("/admin/oportunidades/kazakora/anuncios/{$anuncio->id}/clonar")->assertRedirect();

        $c = KzCriativo::firstOrFail();
        $this->assertMatchesRegularExpression('/^KZ-\d{6}-\d{4}$/', $c->codigo);
        $this->assertSame('clone', $c->origem);
        $this->assertSame('111', $c->referencia_ad_id);
        $this->assertSame('roteiro_pronto', $c->status);
        $this->assertSame('Clone do mop', $c->briefing['titulo']);
        $this->assertSame($c->codigo, $c->briefing['codigo']);
        Storage::disk('local')->assertExists($c->pasta.'/briefing.json');
        Storage::disk('local')->assertExists($c->pasta.'/roteiro.md');
        Storage::disk('local')->assertExists($c->pasta.'/referencia/111.mp4');
    }

    public function test_criar_do_zero_gera_de_3_em_3_ate_completar(): void
    {
        $o = $this->importar();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->gemini(
            [$this->roteiro('A'), $this->roteiro('B'), $this->roteiro('C')],
            [$this->roteiro('D'), $this->roteiro('E')],
        );

        $this->actingAs($admin)->post("/admin/oportunidades/kazakora/{$o->id}/criativos", ['quantidade' => 5])->assertRedirect();

        $criativos = KzCriativo::orderBy('numero')->get();
        $this->assertCount(5, $criativos);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $criativos->pluck('briefing.titulo')->all());
        $this->assertSame(['roteiro_pronto'], $criativos->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $criativos->pluck('lote')->unique()->count());
        $this->assertSame('zero', $criativos->first()->origem);
    }

    public function test_quantidade_acima_do_maximo_e_recusada(): void
    {
        $o = $this->importar();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post("/admin/oportunidades/kazakora/{$o->id}/criativos", ['quantidade' => 50])->assertSessionHasErrors('quantidade');
        $this->assertSame(0, KzCriativo::count());
    }

    public function test_sem_relatorios_view_nao_entra(): void
    {
        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'relatorios.view')->update(['granted' => false]);

        $this->actingAs($assinante)->get('/admin/oportunidades/kazakora')->assertForbidden();
    }
}
