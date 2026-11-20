<?php

namespace Tests\Feature\Infoprodutos;

use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Infoprodutos\Support\EquipeDaLoja;
use App\Modules\Infoprodutos\Support\FunilInfoprodutos;
use App\Modules\Infoprodutos\Support\PaginasDoSite;
use App\Modules\Infoprodutos\Support\PrintDaPagina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Tela Comportamento (2026-11-20): toda página acessada, sem equipe e sem robô, com o funil. */
class PaginasDoSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00:00', 'America/Sao_Paulo'));
        EquipeDaLoja::esquecer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        EquipeDaLoja::esquecer();
        parent::tearDown();
    }

    public function test_caminho_vira_chave_de_pagina(): void
    {
        $this->assertSame(['kzp', 'pagina'], PaginasDoSite::chave('kazakora', '/'));
        $this->assertSame(['kz/arvore', 'produto'], PaginasDoSite::chave('kazakora', '/produtos/arvore'));
        $this->assertSame(['fk/serra', 'produto'], PaginasDoSite::chave('ferrakora', '/produto/serra/?utm_source=x'));
        $this->assertSame(['kzp/departamento/cozinha', 'pagina'], PaginasDoSite::chave('kazakora', '/?categoria=cozinha'));
        $this->assertSame(['kzp/rastreio/_', 'pagina'], PaginasDoSite::chave('kazakora', '/rastreio/AB123456789BR'));
        $this->assertSame(['kzp/finalizacao/_/confirmacao', 'pagina'], PaginasDoSite::chave('kazakora', '/finalizacao/4021/confirmacao'));
        $this->assertNull(PaginasDoSite::chave('kazakora', '/busca/sugestoes'));
        $this->assertNull(PaginasDoSite::chave('kazakora', '/frete/prazo'));
        $this->assertNull(PaginasDoSite::chave('kazakora', '/sitemap.xml'));

        $this->assertSame('https://kazakora.com/', PrintDaPagina::url('kzp', 'pagina'));
        $this->assertSame('https://kazakora.com/?categoria=cozinha', PrintDaPagina::url('kzp/departamento/cozinha', 'pagina'));
        $this->assertSame('https://ferrakora.com/seguro-pagamento/', PrintDaPagina::url('fkp/seguro-pagamento', 'pagina'));
        $this->assertNull(PrintDaPagina::url('kzp/rastreio/_', 'pagina'));
        $this->assertSame('Checkout · entrega', PaginasDoSite::nomeDaPagina('kzp/finalizacao'));
    }

    public function test_lista_toda_pagina_sem_equipe_e_sem_robo_com_o_funil(): void
    {
        config(['infoprodutos.ips_equipe' => ['9.9.9.0/24']]);
        $produto = Product::factory()->create(['name' => 'Árvore de Natal', 'slug' => 'arvore']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $visita = fn (string $visitante, string $path, array $extra = []) => SiteVisit::create($extra + ['site' => 'kazakora', 'visitor_id' => $visitante,
            'path' => $path, 'ip' => ['a' => '1.1.1.1', 'b' => '1.1.1.2', 'c' => '1.1.1.3', 'adm' => '5.5.5.5'][$visitante] ?? '7.7.7.7', 'consentiu' => true]);

        // Cliente A: início -> produto -> carrinho -> checkout -> pedido.
        $visita('a', '/');
        $visita('a', '/produtos/arvore');
        $visita('a', '/carrinho');
        $visita('a', '/finalizacao');
        CheckoutEvento::create(['visitor_id' => 'a', 'evento' => 'produto_carrinho', 'campo' => (string) $produto->id]);
        CheckoutEvento::create(['visitor_id' => 'a', 'evento' => CheckoutEvento::PEDIDO_CRIADO]);
        // Cliente B: só o produto.
        $visita('b', '/produtos/arvore');
        // Departamento.
        $visita('c', '/?categoria=natal');
        // Equipe: logado, o mesmo navegador sem login, e um IP da lista fixa.
        $visita('adm', '/produtos/arvore', ['user_id' => $admin->id]);
        $visita('adm', '/');
        $visita('x', '/', ['ip' => '9.9.9.7']);
        // Robô: mais de 300 páginas no dia.
        for ($i = 0; $i <= SiteVisit::VISITAS_DIA_ROBO; $i++) {
            $visita('robo', '/');
        }

        [$de, $ate] = FunilInfoprodutos::intervalo('7d');
        $r = app(PaginasDoSite::class)->montar($de, $ate);
        $paginas = collect($r['paginas'])->keyBy(fn ($p) => $p['produto'].'|'.$p['pagina']);

        $this->assertSame('Árvore de Natal', $paginas['kz/arvore|produto']['rotulo']);
        $this->assertSame([2, 1, 1, 1], [$paginas['kz/arvore|produto']['visitas'], $paginas['kz/arvore|produto']['carrinho'],
            $paginas['kz/arvore|produto']['checkout'], $paginas['kz/arvore|produto']['pedidos']]);
        $this->assertSame(['Início', 1], [$paginas['kzp|pagina']['rotulo'], $paginas['kzp|pagina']['visitas']]);
        $this->assertSame('Departamento: Natal', $paginas['kzp/departamento/natal|pagina']['rotulo']);
        $this->assertSame(1, $paginas['kzp/carrinho|pagina']['visitas']);
        $this->assertSame(['visitas' => 3, 'carrinho' => 1, 'checkout' => 1, 'pedidos' => 1], array_intersect_key($r['totais']['kazakora'], array_flip(['visitas', 'carrinho', 'checkout', 'pedidos'])));
    }

    public function test_departamento_e_busca_ficam_no_caminho_da_visita(): void
    {
        $this->assertSame('/?categoria=natal', \App\Http\Middleware\TrackSiteVisit::caminho('', ['categoria' => 'natal']));
        $this->assertSame('/?busca', \App\Http\Middleware\TrackSiteVisit::caminho('/', ['search' => 'furadeira']));
        $this->assertSame('/', \App\Http\Middleware\TrackSiteVisit::caminho('/', ['categoria' => '<script>']));
        $this->assertSame('/produtos/x', \App\Http\Middleware\TrackSiteVisit::caminho('produtos/x', ['categoria' => 'natal']));
    }
}
