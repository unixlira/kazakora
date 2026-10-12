<?php

namespace Tests\Feature\Admin;

use App\Models\MercadoLivreToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Radar de Oportunidades (2026-10-12): uma tela só no grupo Oportunidades
 * do menu, sem separar por marketplace. Junta TikTok Shop (Kalodata),
 * Mercado Livre e Google Trends, cada um como prop adiada. A Shopee saiu da
 * tela no layout v4 (Lira 2026-10-12) até a API de Afiliados trazer dados.
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

    /** Resposta da lista "Tendências agora" no formato do Google (batchexecute). */
    private static function agora(): string
    {
        $item = fn (string $termo, int $volume, array $categorias, array $relacionadas) => [$termo, null, 'BR', [1791741600], null, null, $volume, null, 1000, $relacionadas, $categorias];
        $lista = [null, [
            $item('flamengo x fluminense', 1000000, [17], ['onde assistir flamengo']),
            $item('anitta', 50000, [4], ['anitta', 'anitta música nova']),
            $item('iphone 17', 20000, [18], ['iphone 17 preço']),
            $item('frente fria', 10000, [20], ['previsão do tempo']),
            $item('a fazenda 18', 5000, [4], ['a fazenda 18 temporada']),
            $item('virginia', 2000, [4], ['virginia idade']),
        ]];

        return ")]}'\n\n".json_encode([['wrb.fr', 'i0OFE', json_encode($lista), null, null, null, 'generic']])."\n25\n[[\"e\",4]]";
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo motivo do OportunidadesMercadoTest: loadDeferredProps confere o componente.
        config(['inertia.testing.ensure_pages_exist' => false, 'services.gemini.api_key' => null]);
        // O ranking do TikTok fica guardado em arquivo (MaisVendidosTikTok).
        Storage::fake('local');
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
                ->missing('mercadoLivre')
                ->missing('google'));
    }

    public function test_radar_junta_tiktok_mercado_livre_e_google(): void
    {
        config([
            'services.kalodata.api_key' => 'chave-teste',
            // Mesmo com a API de Afiliados configurada, o Radar não consulta a Shopee.
            'services.shopee_afiliados.app_id' => 'app-teste',
            'services.shopee_afiliados.secret' => 'segredo-teste',
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
            'trends.google.com/_/*' => Http::response(self::agora()),
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
                ->missing('shopee')
                ->where('mercadoLivre.produtos.0.nome', 'Organizador de Gaveta')
                ->where('mercadoLivre.tendencias.0.termo', 'cortina blackout')
                ->where('mercadoLivre.buscas.0.termo', 'air fryer')
                ->where('google.erro', null)
                ->has('google.termos', 2)
                ->where('google.termos.0.termo', 'air fryer')
                ->where('google.termos.0.trafego', '20.000+')
                ->where('google.termos.0.noticia', 'Air fryer em promoção')
                ->where('google.termos.1.noticia', null)
                // Cards por assunto sem o Gemini: palavras das buscas
                // relacionadas e categoria do Google; esporte fica só nas gerais.
                ->where('google.erro_grupos', null)
                ->where('google.grupos.musica.0.termo', 'anitta')
                ->where('google.grupos.musica.0.trafego', '50000+')
                ->where('google.grupos.produtos.0.termo', 'iphone 17')
                ->where('google.grupos.noticias.0.termo', 'frente fria')
                ->where('google.grupos.videos.0.termo', 'a fazenda 18')
                ->where('google.grupos.personalidades.0.termo', 'virginia')
                ->where('google.grupos', fn ($grupos) => collect($grupos)->flatten(1)->pluck('termo')->doesntContain('flamengo x fluminense'))));

        // Kalodata: uma chamada só, ranking geral de 7 dias no Brasil.
        Http::assertSentCount(1 + 2 + 4 + 1); // Kalodata + Google (RSS e tendências agora) + ML (ranking, produto, ofertas, tendência da categoria) + buscas do site
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'affiliate.shopee'));
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
                ->where('google.grupos.produtos', [])
                ->where('google.erro_grupos', fn ($erro) => str_contains($erro, 'por assunto'))
                ->where('mercadoLivre.buscas', [])));
    }

    public function test_gemini_separa_as_buscas_do_google_por_assunto(): void
    {
        config(['services.gemini.api_key' => 'chave-teste', 'services.kalodata.api_key' => null]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => "```json\n"
                .json_encode(['0' => 'outro', '1' => 'musica', '2' => 'produtos', '3' => 'noticias', '4' => 'videos', '5' => 'produtos'])
                ."\n```"]]]]]]),
            'trends.google.com/_/*' => Http::response(self::agora()),
            'trends.google.com/*' => Http::response(self::RSS),
            '*' => Http::response([]),
        ]);

        $this->actingAs($this->admin())->get('/admin/radar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('google', fn (Assert $reload) => $reload
                ->where('google.grupos.musica.0.termo', 'anitta')
                ->where('google.grupos.produtos.0.termo', 'iphone 17')
                // Sem o Gemini, "virginia idade" iria para personalidades.
                ->where('google.grupos.personalidades', [])
                ->where('google.grupos.produtos.1.termo', 'virginia')
                ->has('google.grupos.produtos', 2)));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
            && str_contains($r['contents'][0]['parts'][0]['text'], '2 | iphone 17 | Tecnologia | iphone 17 preço'));
    }

    /** Submenu do Radar (Lira 2026-10-12): a mesma tela só com o bloco da fonte. */
    public function test_submenu_abre_so_a_fonte_escolhida(): void
    {
        Http::fake(['*' => Http::response([])]);
        $admin = $this->admin();

        $this->assertSame(url('/admin/radar/tiktok'), route('admin.radar.fonte', 'tiktok'));

        // O bloco do ML fica só em Oportunidades › Mercado Livre (OportunidadesMercadoTest).
        foreach (['tiktok' => ['tiktok', 'TikTok'], 'google' => ['google', 'Google']] as $fonte => [$prop, $nome]) {
            $this->actingAs($admin)->get("/admin/radar/{$fonte}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Radar/Index', false)
                    ->where('fonte', $fonte)
                    ->where('fonteNome', $nome)
                    ->loadDeferredProps(fn (Assert $reload) => $reload
                        ->has($prop)
                        ->etc()));
        }

        // Só o Google: nem o Kalodata nem o Mercado Livre são consultados.
        Http::fake(['*' => Http::response('', 200)]);
        $this->actingAs($admin)->get('/admin/radar/google')
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('google')->missing('tiktok')->missing('mercadoLivre')->etc()));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'kalodata') || str_contains($r->url(), 'mercadolibre'));
    }

    public function test_radar_has_no_page_for_other_channels(): void
    {
        $this->actingAs($this->admin())->get('/admin/radar/shopee')->assertNotFound();
    }

    public function test_customer_cannot_open_radar_page(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get('/admin/radar')->assertForbidden();
    }
}
