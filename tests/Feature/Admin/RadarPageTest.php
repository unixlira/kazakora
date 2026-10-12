<?php

namespace Tests\Feature\Admin;

use App\Models\MercadoLivreToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Radar de Oportunidades (2026-10-12): uma tela só no grupo Oportunidades
 * do menu, sem separar por marketplace. Junta TikTok Shop (Kalodata),
 * Shopee (Afiliados), Mercado Livre e Google Trends, cada um como prop
 * adiada.
 */
class RadarPageTest extends TestCase
{
    use RefreshDatabase;

    private const RSS = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:atom="http://www.w3.org/2005/Atom" xmlns:ht="https://trends.google.com/trending/rss" version="2.0">
<channel>
<item><title>air fryer</title><ht:approx_traffic>20.000+</ht:approx_traffic>
<ht:news_item><ht:news_item_title>Air fryer em promoção</ht:news_item_title></ht:news_item></item>
<item><title>anitta</title><ht:approx_traffic>500+</ht:approx_traffic></item>
</channel>
</rss>
XML;

    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo motivo do OportunidadesMercadoTest: loadDeferredProps confere o componente.
        config(['inertia.testing.ensure_pages_exist' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_radar_page_opens(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->assertSame(url('/admin/radar'), route('admin.radar.index'));

        $this->actingAs($this->admin())
            ->get('/admin/radar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Radar/Index', false)
                ->missing('tiktok')
                ->missing('shopee')
                ->missing('mercadoLivre')
                ->missing('google'));
    }

    public function test_radar_junta_tiktok_mercado_livre_e_google(): void
    {
        config([
            'services.kalodata.api_key' => 'chave-teste',
            'services.shopee_afiliados.app_id' => null,
            'services.shopee_afiliados.secret' => null,
        ]);
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(), 'ml_user_id' => 123456789, 'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token', 'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6), 'scopes' => ['offline_access', 'read', 'write'],
        ]);

        $ml = 'https://api.mercadolibre.com/';
        Http::fake([
            'www.kalodata.com/*' => Http::response(['success' => true, 'code' => 200, 'data' => ['data' => [
                ['product_id' => '17', 'product_name' => 'Air Fryer 4L Digital', 'revenue' => 249241.08, 'sales_volumn' => 4063,
                    'unit_price' => 61.34, 'revenue_growth_rate' => -8.35, 'commission_rate' => 5, 'launch_date' => '2026-07-30', 'master_image_url' => null],
            ]]]),
            'trends.google.com/*' => Http::response(self::RSS, 200, ['Content-Type' => 'application/rss+xml']),
            $ml.'highlights/MLB/category/MLB1574*' => Http::response(['content' => [['id' => 'MLB111', 'position' => 1, 'type' => 'PRODUCT']]]),
            $ml.'products/MLB111/items*' => Http::response(['paging' => ['total' => 4], 'results' => [['seller_id' => 9, 'price' => 49.9]]]),
            $ml.'products/MLB111*' => Http::response(['name' => 'Organizador de Gaveta', 'pictures' => []]),
            $ml.'trends/MLB/MLB1574*' => Http::response([['keyword' => 'cortina blackout', 'url' => 'https://lista.mercadolivre.com.br/cortina-blackout']]),
            $ml.'trends/MLB' => Http::response([['keyword' => 'air fryer', 'url' => 'https://lista.mercadolivre.com.br/air-fryer']]),
        ]);

        $this->actingAs($this->admin())->get('/admin/radar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('tiktok.configurado', true)
                ->where('tiktok.erro', null)
                ->where('tiktok.produtos.0.nome', 'Air Fryer 4L Digital')
                ->where('tiktok.produtos.0.receita', 249241.08)
                ->where('tiktok.produtos.0.vendas', 4063)
                ->where('tiktok.produtos.0.crescimento', -8.35)
                ->where('shopee.configurado', false)
                ->where('mercadoLivre.produtos.0.nome', 'Organizador de Gaveta')
                ->where('mercadoLivre.tendencias.0.termo', 'cortina blackout')
                ->where('mercadoLivre.buscas.0.termo', 'air fryer')
                ->where('google.erro', null)
                ->has('google.termos', 2)
                ->where('google.termos.0.termo', 'air fryer')
                ->where('google.termos.0.trafego', '20.000+')
                ->where('google.termos.0.noticia', 'Air fryer em promoção')
                ->where('google.termos.1.noticia', null)));

        // Kalodata: uma chamada só, ranking geral de 7 dias no Brasil.
        Http::assertSentCount(1 + 1 + 4 + 1); // Kalodata + Google + ML (ranking, produto, ofertas, tendência da categoria) + buscas do site
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tiktok/product/rank')
            && $r['date_range'] === 'last7Day' && $r['region'] === 'BR' && ! isset($r['category_ids']));
    }

    public function test_tiktok_e_google_com_erro_viram_aviso(): void
    {
        config(['services.kalodata.api_key' => null]);
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actingAs($this->admin())->get('/admin/radar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('tiktok.configurado', false)
                ->where('google.termos', [])
                ->where('google.erro', fn ($erro) => str_contains($erro, 'Google Trends'))
                ->where('mercadoLivre.buscas', [])));
    }

    public function test_radar_has_no_page_per_channel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/radar/shopee')->assertNotFound();
        $this->actingAs($admin)->get('/admin/radar/mercado-livre')->assertNotFound();
    }

    public function test_customer_cannot_open_radar_page(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get('/admin/radar')->assertForbidden();
    }
}
