<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coluna Valor de Visitas KazaKora e Visitas FerraKora (pedido do Lira,
 * 2026-11-12): a visita guarda o preço que a página do produto mostrou ao
 * abrir, e a tela mostra esse valor na lista e no olho.
 */
class VisitasValorDoProdutoTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    private const TOKEN = 'token-de-teste';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://kazakora.com', 'services.ferrakora.catalogo_token' => self::TOKEN, 'frete_embutido.ativo' => false]);
    }

    private function produto(array $dados): Product
    {
        return Product::factory()->create(['is_active' => true, 'discount_percentage' => null, 'discount_amount' => null, ...$dados]);
    }

    public function test_pagina_do_produto_da_loja_grava_o_preco_do_produto_sem_variacao(): void
    {
        $this->produto(['slug' => 'caneca-azul', 'price' => 49.90, 'stock' => 5]);

        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/produtos/caneca-azul')->assertOk();

        $visita = SiteVisit::sole();
        $this->assertSame('/produtos/caneca-azul', $visita->path);
        $this->assertSame(49.90, $visita->preco_visto);
    }

    public function test_com_variacao_grava_o_da_mais_barata_com_estoque_como_a_pagina_mostra(): void
    {
        $pai = $this->produto(['slug' => 'arvore-natal', 'price' => 300.00, 'stock' => 2, 'variation' => 'Modelo 303']);
        $this->produto(['parent_product_id' => $pai->id, 'price' => 180.00, 'stock' => 4, 'variation' => 'Modelo 180']);
        // A mais barata de todas está sem estoque: a página não usa o preço dela.
        $this->produto(['parent_product_id' => $pai->id, 'price' => 90.00, 'stock' => 0, 'variation' => 'Modelo 90']);

        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/produtos/arvore-natal')->assertOk();

        $this->assertSame(180.00, SiteVisit::sole()->preco_visto);
    }

    public function test_pagina_que_nao_e_de_produto_fica_sem_valor(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/politica-de-entrega')->assertOk();

        $this->assertNull(SiteVisit::sole()->preco_visto);
    }

    public function test_aviso_do_ferrakora_grava_o_preco_da_pagina_do_produto(): void
    {
        $this->produto(['sku' => 'FER-TRE-5M', 'slug' => 'trena-emborrachada-5m', 'name' => 'Trena Emborrachada 5m', 'price' => 39.90, 'stock' => 8]);
        $this->postJson('/api/ferrakora/produtos', ['produtos' => [['sku' => 'FER-TRE-5M', 'categoria' => 'Trenas', 'ordem' => 1]]], ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();
        $slug = $this->getJson('/api/ferrakora/produtos')->json('products.0.slug');
        $naApi = $this->getJson('/api/ferrakora/produtos/'.$slug)->json('product.variants.0.prices.0');

        $aviso = fn (string $pagina) => $this->withHeaders(['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => self::NAVEGADOR])
            ->post('/api/ferrakora/visita?p='.urlencode($pagina))->assertNoContent();
        $aviso('/produto/'.$slug);
        $aviso('/produtos');
        $aviso('/produto/nao-existe');

        $visitas = SiteVisit::query()->orderBy('id')->get();
        $this->assertSame('ferrakora', $visitas[0]->site);
        // O mesmo valor que a API do catálogo entrega para a página.
        $this->assertSame(($naApi['sale_amount'] ?? $naApi['amount']) / 100, $visitas[0]->preco_visto);
        $this->assertGreaterThan(0, $visitas[0]->preco_visto);
        $this->assertNull($visitas[1]->preco_visto);
        $this->assertNull($visitas[2]->preco_visto);
    }

    public function test_tela_mostra_o_ultimo_produto_aberto_os_outros_e_o_valor_de_cada_pagina_no_olho(): void
    {
        $this->travelTo('2026-11-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $visita = fn (string $ip, string $hora, string $path, ?float $preco, string $site = 'kazakora') => DB::table('site_visits')->insert([
            'site' => $site, 'visitor_id' => 'v-'.$ip, 'path' => $path, 'preco_visto' => $preco, 'ip' => $ip,
            'consentiu' => true, 'created_at' => '2026-11-12 '.$hora, 'dispositivo' => 'celular', 'origem' => 'direto',
        ]);
        $visita('1.1.1.1', '10:00:00', '/', null);
        $visita('1.1.1.1', '10:01:00', '/produtos/caneca', 49.90);
        $visita('1.1.1.1', '10:02:00', '/produtos/arvore', 180.00);
        // Abriu a caneca de novo, já com outro preço: vale o da última vez.
        $visita('1.1.1.1', '10:03:00', '/produtos/caneca', 44.90);
        $visita('2.2.2.2', '11:00:00', '/', null);
        $visita('3.3.3.3', '12:00:00', '/produto/trena', 39.90, 'ferrakora');

        $this->actingAs($admin)->get('/admin/visitas-site?todos=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 2)
                ->where('visitas.data.0.ip', '2.2.2.2')
                ->where('visitas.data.0.produto', null)
                ->where('visitas.data.1.ip', '1.1.1.1')
                ->where('visitas.data.1.produto.path', '/produtos/caneca')
                ->where('visitas.data.1.produto.valor', 44.9)
                ->where('visitas.data.1.produto.mais', 1)
                ->where('visitas.data.1.produto.outros.0.path', '/produtos/arvore')
                ->where('visitas.data.1.produto.outros.0.valor', 180));

        $this->actingAs($admin)->get('/admin/visitas-ferrakora?todos=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 1)
                ->where('visitas.data.0.produto.path', '/produto/trena')
                ->where('visitas.data.0.produto.valor', 39.9)
                ->where('visitas.data.0.produto.mais', 0));

        $this->actingAs($admin)->getJson('/admin/visitas-site/visitante?de=2026-11-12&ate=2026-11-12&ip=1.1.1.1&todos=1')
            ->assertOk()
            ->assertJsonPath('visitas.0.valor', null)
            ->assertJsonPath('visitas.1.valor', 49.9)
            ->assertJsonPath('visitas.2.valor', 180)
            ->assertJsonPath('visitas.3.valor', 44.9);
    }
}
