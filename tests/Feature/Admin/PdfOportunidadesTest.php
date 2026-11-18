<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\PdfEscolhido;
use App\Modules\Admin\Models\PdfOferta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Oportunidades em PDF (2026-11-17): ofertas reais da Biblioteca de Anúncios
 * importadas do ofertas.json do coletor do Homer.
 */
class PdfOportunidadesTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->pasta = sys_get_temp_dir().'/radar-pdf-teste-'.uniqid();
        mkdir($this->pasta.'/01-planner', 0777, true);
        file_put_contents($this->pasta.'/01-planner/111.mp4', 'video-fake');
        file_put_contents($this->pasta.'/01-planner/111.jpg', 'poster-fake');
        file_put_contents($this->pasta.'/01-planner/222.jpg', 'imagem-fake');
    }

    private function ofertasJson(array $anuncios = ['111', '222'], string $nome = 'Planner Digital 2026'): string
    {
        $ads = [];
        foreach ($anuncios as $id) {
            $ads[] = [
                'id' => $id,
                'texto' => "Descarga inmediata del planner {$id}",
                'titulo' => 'Planner 2026',
                'cta' => 'Comprar ahora',
                'iniciado_em' => '2026-09-01',
                'variacoes' => 3,
                'plataformas' => ['FACEBOOK', 'INSTAGRAM'],
                'midias' => $id === '111'
                    ? [['tipo' => 'video', 'arquivo' => '01-planner/111.mp4', 'poster' => '01-planner/111.jpg']]
                    : [['tipo' => 'imagem', 'arquivo' => '01-planner/222.jpg']],
            ];
        }
        $arquivo = $this->pasta.'/ofertas.json';
        file_put_contents($arquivo, json_encode([
            'gerado_em' => '2026-11-17T23:00:00Z',
            'ofertas' => [[
                'chave' => 'pay.hotmart.com/X123',
                'posicao' => 1,
                'nome' => $nome,
                'nicho' => 'Planner',
                'idioma' => 'es',
                'anunciante' => 'Planners MX',
                'pagina_id' => '999',
                'url_destino' => 'https://pay.hotmart.com/X123',
                'dominio' => 'pay.hotmart.com',
                'plataforma' => 'Hotmart',
                'preco' => 9.9,
                'moeda' => 'USD',
                'paises' => ['MX', 'CO'],
                'anuncios_ativos' => 42,
                'variacoes' => 6,
                'inicio_mais_antigo' => '2026-09-01',
                'dias_rodando' => 77,
                'pontuacao' => 88.5,
                'anuncios' => $ads,
            ]],
        ]));

        return $arquivo;
    }

    public function test_importador_e_idempotente_e_copia_as_midias(): void
    {
        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson()])->assertSuccessful();
        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson(['111'], 'Planner Digital 2027')])->assertSuccessful();

        $this->assertSame(1, PdfOferta::count());
        $oferta = PdfOferta::firstOrFail();
        $this->assertSame('Planner Digital 2027', $oferta->nome);
        $this->assertSame(['MX', 'CO'], $oferta->paises);
        $this->assertSame(['111'], $oferta->anuncios()->pluck('ad_archive_id')->all());
        $this->assertSame(1, DB::table('pdf_oferta_anuncios')->count());

        $midia = $oferta->anuncios()->first()->midias[0];
        $this->assertSame('video', $midia['tipo']);
        Storage::disk('public')->assertExists($midia['caminho']);
        Storage::disk('public')->assertExists($midia['poster']);
        $this->assertSame($midia['poster'], $oferta->miniatura);
    }

    public function test_oferta_que_saiu_do_arquivo_fica_inativa(): void
    {
        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson()])->assertSuccessful();
        $antiga = PdfOferta::create(['chave' => 'velha.com', 'nome' => 'Velha', 'ativa' => true, 'posicao' => 2]);

        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson()])->assertSuccessful();

        $this->assertFalse($antiga->fresh()->ativa);
        $this->assertTrue(PdfOferta::where('chave', 'pay.hotmart.com/X123')->first()->ativa);
    }

    public function test_telas_abrem_com_os_criativos(): void
    {
        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson()])->assertSuccessful();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $oferta = PdfOferta::firstOrFail();

        $this->actingAs($admin)->get('/admin/pdf-oportunidades')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/PdfOpportunities/Index', false)
                ->has('ofertas', 1)
                ->where('ofertas.0.anunciosAtivos', 42)
                ->where('ofertas.0.idioma', 'es'));

        $this->actingAs($admin)->get('/admin/pdf-oportunidades/'.$oferta->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/PdfOpportunities/Show', false)
                ->has('anuncios', 2)
                ->where('anuncios.0.link', fn ($link) => str_starts_with($link, 'https://www.facebook.com/ads/library/?id='))
                ->where('oferta.urlDestino', 'https://pay.hotmart.com/X123'));
    }

    public function test_sem_relatorios_view_nao_entra(): void
    {
        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'relatorios.view')->update(['granted' => false]);

        $this->actingAs($assinante)->get('/admin/pdf-oportunidades')->assertForbidden();
    }

    public function test_cliente_nao_entra(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $resposta = $this->actingAs($cliente)->get('/admin/pdf-oportunidades');
        $this->assertContains($resposta->status(), [302, 403]);
    }

    public function test_clonar_copia_oferta_criativos_plano_e_prompt(): void
    {
        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson()])->assertSuccessful();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $oferta = PdfOferta::firstOrFail();

        $this->actingAs($admin)->post('/admin/pdf-oportunidades/'.$oferta->id.'/clonar')->assertRedirect();
        $this->actingAs($admin)->post('/admin/pdf-oportunidades/'.$oferta->id.'/clonar')->assertRedirect();

        $this->assertSame(1, PdfEscolhido::count());
        $escolhido = PdfEscolhido::firstOrFail();
        $this->assertSame('Planner Digital 2026', $escolhido->nome);
        $this->assertSame($admin->id, $escolhido->user_id);
        $this->assertCount(2, $escolhido->anuncios);
        $this->assertCount(5, $escolhido->plano_acao);
        $this->assertStringContainsString('Planner Digital 2026', $escolhido->prompt_entregavel);
        $this->assertStringContainsString('Planner imprimível', $escolhido->prompt_entregavel);
        $this->assertStringContainsString('Descarga inmediata del planner 111', $escolhido->prompt_entregavel);

        // A mídia é copiada para a pasta do clone e sobrevive à reimportação que apaga o anúncio.
        $midia = $escolhido->anuncios[0]['midias'][0];
        $this->assertStringStartsWith('pdf-escolhidos/'.$escolhido->id.'/', $midia['caminho']);
        Storage::disk('public')->assertExists($midia['caminho']);
        Storage::disk('public')->assertExists($escolhido->miniatura);

        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson(['111'])])->assertSuccessful();
        $this->assertCount(2, $escolhido->fresh()->anuncios);
    }

    public function test_telas_dos_escolhidos(): void
    {
        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson()])->assertSuccessful();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $oferta = PdfOferta::firstOrFail();
        $this->actingAs($admin)->post('/admin/pdf-oportunidades/'.$oferta->id.'/clonar');
        $escolhido = PdfEscolhido::firstOrFail();

        $this->actingAs($admin)->get('/admin/pdf-oportunidades')
            ->assertInertia(fn (Assert $page) => $page->where('totalEscolhidos', 1)->where('ofertas.0.escolhidoId', $escolhido->id));

        $this->actingAs($admin)->get('/admin/pdf-oportunidades/escolhidos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/PdfOpportunities/Escolhidos', false)
                ->has('escolhidos', 1)
                ->where('escolhidos.0.totalMidias', 2));

        $this->actingAs($admin)->get('/admin/pdf-oportunidades/escolhidos/'.$escolhido->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/PdfOpportunities/Escolhido', false)
                ->has('anuncios', 2)
                ->has('escolhido.planoAcao', 5)
                ->where('escolhido.dados.urlDestino', 'https://pay.hotmart.com/X123')
                ->where('anuncios.0.iniciadoEm', '01/09/2026'));

        $this->actingAs($admin)->delete('/admin/pdf-oportunidades/escolhidos/'.$escolhido->id)->assertRedirect('/admin/pdf-oportunidades/escolhidos');
        $this->assertSame(0, PdfEscolhido::count());
        Storage::disk('public')->assertMissing('pdf-escolhidos/'.$escolhido->id);
    }

    public function test_sem_relatorios_view_nao_clona(): void
    {
        $this->artisan('pdf-oportunidades:importar', ['arquivo' => $this->ofertasJson()])->assertSuccessful();
        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'relatorios.view')->update(['granted' => false]);

        $this->actingAs($assinante)->post('/admin/pdf-oportunidades/'.PdfOferta::firstOrFail()->id.'/clonar')->assertForbidden();
        $this->assertSame(0, PdfEscolhido::count());
    }
}
