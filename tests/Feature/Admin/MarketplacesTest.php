<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Support\CatalogoMarketplaces;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Menu Marketplaces (Lira, 2026-10-17): lista, abas de cada marketplace,
 * telas antigas reaproveitadas dentro das abas e links antigos de pé.
 * Nenhuma chamada externa: Http::preventStrayRequests em todos.
 */
class MarketplacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Páginas ficam em resources/js/Modules, fora do caminho padrão do Inertia.
        config(['inertia.testing.ensure_pages_exist' => false, 'services.gemini.api_key' => null]);
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00', 'America/Sao_Paulo'));
        // Saldo de ADS da Shopee (aba Gerar campanha): nunca vai à API.
        $this->mock(ShopeeAdsService::class, fn ($m) => $m->shouldReceive('currentBalance')->andReturn(100.0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_menu_tem_o_grupo_marketplaces_com_os_quatro_marketplaces(): void
    {
        $menu = file_get_contents(resource_path('js/Shared/adminSidebarItems.js'));

        $this->assertStringContainsString("heading: 'Marketplaces'", $menu);
        $this->assertStringContainsString("href: '/admin/marketplaces'", $menu);
        foreach (CatalogoMarketplaces::slugs() as $slug) {
            $this->assertStringContainsString("href: '/admin/marketplaces/{$slug}'", $menu);
        }
    }

    public function test_lista_mostra_os_marketplaces_integrados_com_logo_local(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/marketplaces')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Marketplaces/Index')
                ->has('marketplaces', 4)
                ->where('marketplaces.0.slug', 'mercado-livre')
                ->where('marketplaces.1.slug', 'shopee')
                ->where('marketplaces.2.slug', 'tiktok-shop')
                ->where('marketplaces.3.slug', 'amazon'));

        // Logotipo é arquivo do projeto, nunca link externo.
        foreach (CatalogoMarketplaces::todos() as $m) {
            $this->assertStringStartsWith('/images/marketplaces/', $m['logo']);
            $this->assertFileExists(public_path(ltrim($m['logo'], '/')));
        }
    }

    public function test_cada_marketplace_e_cada_aba_abre_para_admin(): void
    {
        $admin = $this->admin();

        foreach (CatalogoMarketplaces::todos() as $slug => $m) {
            $this->actingAs($admin)->get("/admin/marketplaces/{$slug}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('marketplaceNav.aba', 'oportunidades'));

            foreach (array_keys(CatalogoMarketplaces::ABAS) as $aba) {
                $this->actingAs($admin)->get("/admin/marketplaces/{$slug}/{$aba}")
                    ->assertOk()
                    ->assertInertia(fn (Assert $page) => $page
                        ->where('marketplaceNav.slug', $slug)
                        ->where('marketplaceNav.aba', $aba)
                        ->has('marketplaceNav.abas', 6));
            }
        }
    }

    public function test_abas_ligadas_renderizam_a_tela_que_ja_existe(): void
    {
        $admin = $this->admin();
        $esperado = [
            'mercado-livre/oportunidades' => 'Admin/Oportunidades/Mercado',
            'mercado-livre/imagens' => 'Admin/Marketplaces/AdPhotos/Index',
            'mercado-livre/cupom' => 'Admin/Integracoes/MercadoLivre/Cupom',
            'mercado-livre/promocao' => 'Admin/Marketplaces/Aba',
            'shopee/oportunidades' => 'Admin/Oportunidades/Mercado',
            'shopee/imagens' => 'Admin/Marketplaces/AdPhotos/Index',
            'shopee/campanha' => 'Admin/Publicidade/Shopee',
            'shopee/cupom' => 'Admin/Marketplaces/Aba',
            'tiktok-shop/oportunidades' => 'Admin/Radar/Index',
            'amazon/oportunidades' => 'Admin/Marketplaces/Aba',
        ];

        foreach ($esperado as $url => $componente) {
            $this->actingAs($admin)->get("/admin/marketplaces/{$url}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($componente));
        }

        // Filtro do marketplace aplicado nas fotos de anúncio.
        $this->actingAs($admin)->get('/admin/marketplaces/shopee/imagens')
            ->assertInertia(fn (Assert $page) => $page->where('filters.marketplace', 'shopee'));
        // Oportunidades da Shopee navegam dentro da aba.
        $this->actingAs($admin)->get('/admin/marketplaces/shopee/oportunidades')
            ->assertInertia(fn (Assert $page) => $page->where('base', '/admin/marketplaces/shopee/oportunidades')->where('canal.slug', 'shopee'));
        // TikTok Shop mostra só o bloco do TikTok do Radar.
        $this->actingAs($admin)->get('/admin/marketplaces/tiktok-shop/oportunidades')
            ->assertInertia(fn (Assert $page) => $page->where('fonte', 'tiktok'));
    }

    public function test_aba_sem_tela_mostra_em_breve_sem_dado(): void
    {
        $this->actingAs($this->admin())->get('/admin/marketplaces/amazon/cupom')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Marketplaces/Aba')
                ->where('estado', 'em-breve')
                ->where('texto', fn ($t) => is_string($t) && $t !== '')
                ->where('marketplaceNav.origem', null));
    }

    public function test_aba_cupom_do_ml_abre_com_o_modelo_e_sem_chamada_externa(): void
    {
        config([
            'mercadolivre.cupom.percentual' => 50,
            'mercadolivre.cupom.desconto_maximo' => 6,
            'mercadolivre.cupom.compra_minima_pct_ticket' => 2,
        ]);

        $this->actingAs($this->admin())->get('/admin/marketplaces/mercado-livre/cupom')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Integracoes/MercadoLivre/Cupom')
                ->where('modelo.percentual', 50)
                ->where('modelo.desconto_maximo', 6)
                ->where('modelo.compra_minima_pct_ticket', 2)
                ->where('envioHabilitado', false)
                ->where('marketplaceNav.aba', 'cupom')
                ->where('marketplaceNav.origem.href', '/admin/integracoes/mercado-livre/cupom'));

        // Só abrir a aba não fala com o Mercado Livre.
        Http::assertNothingSent();
        $this->assertDatabaseCount('mercado_livre_cupons', 0);
    }

    public function test_cupom_do_ml_fica_bloqueado_para_quem_nao_e_admin(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($gerente)->get('/admin/marketplaces/mercado-livre/cupom')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Marketplaces/Aba')
                ->where('estado', 'sem-permissao'));
    }

    public function test_marketplace_ou_aba_desconhecidos_dao_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/marketplaces/shein')->assertNotFound();
        $this->actingAs($admin)->get('/admin/marketplaces/shopee/qualquer')->assertNotFound();
    }

    public function test_cliente_nao_entra(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente)->get('/admin/marketplaces')->assertForbidden();
        $this->actingAs($cliente)->get('/admin/marketplaces/mercado-livre/cupom')->assertForbidden();
    }

    public function test_links_antigos_continuam_funcionando(): void
    {
        $admin = $this->admin();

        foreach ([
            '/admin/integracoes/mercado-livre/cupom' => 'Admin/Integracoes/MercadoLivre/Cupom',
            '/admin/oportunidades/mercado-livre' => 'Admin/Oportunidades/Mercado',
            '/admin/oportunidades/shopee' => 'Admin/Oportunidades/Mercado',
            '/admin/radar/tiktok' => 'Admin/Radar/Index',
            '/admin/publicidade/shopee' => 'Admin/Publicidade/Shopee',
            '/admin/marketplaces/fotos-anuncio' => 'Admin/Marketplaces/AdPhotos/Index',
        ] as $url => $componente) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($componente)->missing('marketplaceNav'));
        }
    }
}
