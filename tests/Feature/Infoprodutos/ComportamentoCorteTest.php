<?php

namespace Tests\Feature\Infoprodutos;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Infoprodutos\Models\ComportamentoCorte;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "Zerar a partir de agora" por página (pedido do Lira em 2026-11-26): a
 * página do livro mudou de layout e o mapa mostrava pontos da tela antiga.
 * Nada é apagado; a tela da página só lê o que veio depois do corte.
 */
class ComportamentoCorteTest extends TestCase
{
    use RefreshDatabase;

    private function evento(string $sessao, string $tipo, string $quando, array $extra = []): void
    {
        InfoprodutoEvento::create($extra + ['sessao' => $sessao, 'produto' => 'kz/livro', 'pagina' => 'produto', 'tipo' => $tipo,
            'teste' => false, 'dispositivo' => 'mobile', 'created_at' => $quando]);
    }

    public function test_zerar_grava_quem_e_quando_e_a_tela_le_so_depois_do_corte(): void
    {
        Bus::fake();
        config(['inertia.testing.ensure_pages_exist' => false]);
        Cache::flush();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Lira']);

        // Visita antiga (layout velho), com clique.
        $this->evento('antigaAAAAAAAAAAAAAA', 'pageview', now()->subHours(3));
        $this->evento('antigaAAAAAAAAAAAAAA', 'clk', now()->subHours(3), ['x' => 10, 'y' => 900, 'largura' => 390, 'alvo' => 'b']);
        SiteVisit::create(['site' => 'kazakora', 'visitor_id' => 'velho', 'path' => '/produtos/livro', 'ip' => '1.1.1.1', 'consentiu' => true])
            ->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->actingAs($admin)->post('/admin/publicidade/comportamento/zerar', ['produto' => 'kz/livro', 'pagina' => 'produto', 'motivo' => 'layout novo'])
            ->assertRedirect();
        $corte = ComportamentoCorte::first();
        $this->assertSame([$admin->id, 'kz/livro', 'layout novo'], [$corte->user_id, $corte->produto, $corte->motivo]);

        // Visita nova, depois do corte.
        $this->travel(5)->minutes();
        $this->evento('novaBBBBBBBBBBBBBBBB', 'pageview', now());
        $this->evento('novaBBBBBBBBBBBBBBBB', 'clk', now(), ['x' => 50, 'y' => 200, 'largura' => 390, 'alvo' => 'b']);
        SiteVisit::create(['site' => 'kazakora', 'visitor_id' => 'novo', 'path' => '/produtos/livro', 'ip' => '1.1.1.2', 'consentiu' => true]);

        $this->actingAs($admin)->get('/admin/publicidade/comportamento?produto=kz/livro&pagina=produto&periodo=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('corte.ativo', true)->where('corte.quem', 'Lira')->where('corte.motivo', 'layout novo')
                ->where('pagina.visitas', 1)->where('pagina.medidas', 1)
                ->loadDeferredProps(fn ($p) => $p->where('dados.visitas', 1)->where('dados.pontos.0.x', 50)->etc())
                ->etc());

        // Nada foi apagado.
        $this->assertSame(4, InfoprodutoEvento::count());
    }

    public function test_comando_zera_pela_chave_atual(): void
    {
        $this->artisan('comportamento:zerar', ['produto' => ['kz/livro', 'fk/compressor'], '--motivo' => 'escada de kits'])->assertSuccessful();

        $this->assertSame(['fk/compressor', 'kz/livro'], ComportamentoCorte::pluck('produto')->sort()->values()->all());
        $this->assertNotNull(ComportamentoCorte::atual('kz/livro', 'produto'));
        $this->assertNull(ComportamentoCorte::atual('kz/livro', 'pagina'));
    }
}
