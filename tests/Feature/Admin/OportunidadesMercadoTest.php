<?php

namespace Tests\Feature\Admin;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Oportunidades (correção do Lira 2026-10-12): a tela mostra produtos de
 * TERCEIROS que mais vendem ou estão em alta no Mercado Livre e na Shopee,
 * e não as vendas da própria KazaKora.
 */
class OportunidadesMercadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Os dados de mercado são prop adiada: loadDeferredProps() refaz a
        // requisição e confere o componente, que fica em Modules/ e não na
        // pasta padrão do Inertia (mesmo motivo do component(..., false)).
        config(['inertia.testing.ensure_pages_exist' => false]);

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(), 'ml_user_id' => 123456789, 'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token', 'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6), 'scopes' => ['offline_access', 'read', 'write'],
        ]);
        MarketplaceAccount::query()->create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456789', 'connected_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function fakeMercadoLivre(): void
    {
        $api = 'https://api.mercadolibre.com/';
        Http::fake([
            $api.'sites/MLB/categories*' => Http::response([['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'], ['id' => 'MLB263532', 'name' => 'Ferramentas']]),
            $api.'highlights/MLB/category/MLB263532*' => Http::response(['content' => [
                ['id' => 'MLB111', 'position' => 1, 'type' => 'PRODUCT'],
                ['id' => 'MLB4002919471', 'position' => 2, 'type' => 'ITEM'],
                ['id' => 'MLB222', 'position' => 3, 'type' => 'PRODUCT'],
            ]]),
            $api.'products/MLB111/items*' => Http::response(['paging' => ['total' => 12], 'results' => [
                ['seller_id' => 999, 'price' => 89.9], ['seller_id' => 888, 'price' => 79.5],
            ]]),
            $api.'products/MLB222/items*' => Http::response(['paging' => ['total' => 3], 'results' => [
                ['seller_id' => 123456789, 'price' => 49.9, 'item_id' => 'MLB5550001'],
            ]]),
            $api.'products/MLB111*' => Http::response(['name' => 'Parafusadeira 12V', 'permalink' => '', 'pictures' => [['url' => 'https://http2.mlstatic.com/a.jpg']]]),
            $api.'products/MLB222*' => Http::response(['name' => 'Jogo de Brocas', 'permalink' => 'https://www.mercadolivre.com.br/jogo/p/MLB222', 'pictures' => []]),
            $api.'trends/MLB/MLB263532*' => Http::response([['keyword' => 'furadeira', 'url' => 'https://lista.mercadolivre.com.br/furadeira']]),
        ]);
    }

    public function test_mercado_livre_mostra_mais_vendidos_de_terceiros_e_buscas_em_alta(): void
    {
        $this->fakeMercadoLivre();
        $produto = Product::factory()->create(['cost_price' => 20]);
        ProductChannelListing::query()->create([
            'product_id' => $produto->id, 'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_id' => 'MLB5550001', 'status' => ProductChannelListing::STATUS_PUBLISHED,
        ]);

        $this->actingAs($this->admin())->get('/admin/oportunidades/mercado-livre?categoria=MLB263532')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Oportunidades/Mercado', false)
                ->where('canal.nome', 'Mercado Livre')
                ->where('filtros.categoria', 'MLB263532')
                ->where('categorias.1.nome', 'Ferramentas')
                ->missing('mercado')
                ->missing('mercadoLivre')
                ->loadDeferredProps('default', fn (Assert $reload) => $reload
                ->where('mercado.erro', null)
                ->where('mercado.ocultos', 1)
                ->has('mercado.produtos', 2)
                // Concorrência (Lira 2026-10-13): 3 vendedores e sem guerra =
                // nicho, sobe para o topo mesmo sendo o 3º do ranking.
                ->where('mercado.produtos.0.nome', 'Jogo de Brocas')
                ->where('mercado.produtos.0.posicao', 3)
                ->where('mercado.produtos.0.voce_vende', true)
                ->where('mercado.produtos.0.concorrencia', 'baixa')
                ->where('mercado.produtos.0.oportunidade_nicho', true)
                ->where('mercado.produtos.0.preco_mediana', 49.9)
                ->where('mercado.produtos.0.custo_maximo', 27.94)
                // Custo pelo vínculo exato do anúncio (item_id) com o produto nosso.
                ->where('mercado.produtos.0.custo', 20)
                ->where('mercado.produtos.0.margem_estimada', fn ($m) => abs($m - ((49.9 * 0.56 - 20) / 49.9 + 0.15)) < 0.0001)
                ->where('mercado.produtos.1.nome', 'Parafusadeira 12V')
                ->where('mercado.produtos.1.posicao', 1)
                ->where('mercado.produtos.1.link', 'https://www.mercadolivre.com.br/p/MLB111')
                ->where('mercado.produtos.1.preco_min', 79.5)
                ->where('mercado.produtos.1.preco_max', 89.9)
                ->where('mercado.produtos.1.vendedores', 12)
                ->where('mercado.produtos.1.voce_vende', false)
                ->where('mercado.produtos.1.concorrencia', 'alta')
                ->where('mercado.produtos.1.oportunidade_nicho', false)
                ->where('mercado.produtos.1.custo', null)
                ->where('mercado.produtos.1.margem_estimada', null)
                ->where('mercado.tendencias.0.termo', 'furadeira')));

        // Nada das vendas da loja: só chamadas de mercado (ranking, catálogo, tendências).
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/orders') || str_contains($r->url(), '/users/'));
    }

    /**
     * Tela dividida (Lira 2026-10-12): embaixo dos mais vendidos vem o bloco
     * do Radar (Casa + buscas do site). O endereço antigo do Radar do ML
     * redireciona para cá (sem item Radar no menu, Lira 2026-10-12).
     */
    public function test_mercado_livre_traz_o_radar_embaixo_e_endereco_antigo_redireciona(): void
    {
        $api = 'https://api.mercadolibre.com/';
        Http::fake([
            $api.'sites/MLB/categories*' => Http::response([['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração']]),
            $api.'highlights/MLB/category/MLB1574*' => Http::response(['content' => [['id' => 'MLB111', 'position' => 1, 'type' => 'PRODUCT']]]),
            $api.'products/MLB111/items*' => Http::response(['paging' => ['total' => 4], 'results' => [['seller_id' => 9, 'price' => 49.9]]]),
            $api.'products/MLB111*' => Http::response(['name' => 'Organizador de Gaveta', 'pictures' => []]),
            $api.'trends/MLB/MLB1574*' => Http::response([['keyword' => 'cortina blackout', 'url' => 'https://lista.mercadolivre.com.br/cortina-blackout']]),
            $api.'trends/MLB' => Http::response([['keyword' => 'air fryer', 'url' => 'https://lista.mercadolivre.com.br/air-fryer']]),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/radar/mercado-livre')
            ->assertRedirect('/admin/oportunidades/mercado-livre');

        $this->actingAs($admin)->get('/admin/oportunidades/mercado-livre')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Oportunidades/Mercado', false)
                ->where('canal.slug', 'mercado-livre')
                ->where('base', '/admin/oportunidades/mercado-livre')
                ->missing('mercadoLivre')
                ->loadDeferredProps('radar', fn (Assert $reload) => $reload
                    ->where('mercadoLivre.produtos.0.nome', 'Organizador de Gaveta')
                    ->where('mercadoLivre.buscas.0.termo', 'air fryer')
                    ->etc()));
    }

    public function test_categoria_invalida_volta_para_a_padrao_e_canal_desconhecido_da_404(): void
    {
        Http::fake(['*' => Http::response([])]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/oportunidades/mercado-livre?categoria=lixo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filtros.categoria', 'MLB1574'));
        $this->actingAs($admin)->get('/admin/oportunidades/tiktok')->assertNotFound();
    }

    public function test_erro_do_mercado_livre_vira_aviso_na_tela(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->actingAs($this->admin())->get('/admin/oportunidades/mercado-livre')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('mercado.produtos', [])
                ->where('mercado.erro', fn ($erro) => str_contains($erro, 'Mercado Livre'))));
    }

    public function test_shopee_sem_api_de_afiliados_mostra_o_que_falta(): void
    {
        config(['services.shopee_afiliados.app_id' => null, 'services.shopee_afiliados.secret' => null]);
        Http::fake();

        $this->actingAs($this->admin())->get('/admin/oportunidades/shopee')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Oportunidades/Mercado', false)
                ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('mercado.configurado', false)
                ->where('mercado.produtos', [])));

        Http::assertNothingSent();
    }

    public function test_shopee_busca_mais_vendidos_pela_api_de_afiliados_assinada(): void
    {
        config(['services.shopee_afiliados.app_id' => '18000000001', 'services.shopee_afiliados.secret' => 'segredo']);
        MarketplaceAccount::query()->create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '777', 'connected_at' => now(),
        ]);
        Http::fake(['open-api.affiliate.shopee.com.br/*' => Http::response(['data' => ['productOfferV2' => ['nodes' => [
            ['itemId' => 1, 'shopId' => 5, 'productName' => 'Organizador de Gaveta', 'imageUrl' => 'https://cf.shopee.com.br/a', 'priceMin' => '19.90', 'priceMax' => '29.90', 'sales' => 15230, 'ratingStar' => '4.8', 'shopName' => 'Loja X', 'productLink' => 'https://shopee.com.br/product/5/1'],
            ['itemId' => 2, 'shopId' => 777, 'productName' => 'Luminária', 'priceMin' => '39.90', 'priceMax' => '39.90', 'sales' => 900, 'shopName' => 'KazaKora', 'productLink' => 'https://shopee.com.br/product/777/2'],
        ]]]])]);

        $this->actingAs($this->admin())->get('/admin/oportunidades/shopee?termo=organizador')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filtros.termo', 'organizador')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('mercado.configurado', true)
                ->where('mercado.produtos.0.nome', 'Organizador de Gaveta')
                ->where('mercado.produtos.0.vendas', 15230)
                ->where('mercado.produtos.0.preco_min', 19.9)
                ->where('mercado.produtos.0.loja', 'Loja X')
                ->where('mercado.produtos.0.voce_vende', false)
                ->where('mercado.produtos.1.voce_vende', true)));

        Http::assertSent(function (Request $r) {
            $corpo = $r->body();
            preg_match('/Timestamp=(\d+), Signature=([0-9a-f]+)/', $r->header('Authorization')[0] ?? '', $m);

            return str_contains($corpo, 'keyword: \"organizador\"')
                && str_contains($corpo, 'sortType: 2')
                && str_starts_with($r->header('Authorization')[0], 'SHA256 Credential=18000000001, ')
                && $m && $m[2] === hash('sha256', '18000000001'.$m[1].$corpo.'segredo');
        });
    }

    public function test_cliente_nao_abre_e_visitante_vai_para_o_login(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))->get('/admin/oportunidades/shopee')->assertForbidden();
        auth()->logout();
        $this->get('/admin/oportunidades/mercado-livre')->assertRedirect();
    }
}
