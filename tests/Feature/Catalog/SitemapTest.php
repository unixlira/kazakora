<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_lista_produtos_ativos_e_deixa_de_fora_inativos_e_variacoes(): void
    {
        Cache::forget('sitemap.xml');
        $ativo = Product::factory()->create(['slug' => 'produto-ativo', 'is_active' => true]);
        Product::factory()->create(['slug' => 'produto-inativo', 'is_active' => false]);
        Product::factory()->create(['slug' => 'variacao-rosa', 'is_active' => true, 'parent_product_id' => $ativo->id]);

        $resposta = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringContainsString('application/xml', $resposta->headers->get('Content-Type'));
        $corpo = $resposta->getContent();
        $this->assertStringContainsString('/produtos/produto-ativo', $corpo);
        $this->assertStringNotContainsString('produto-inativo', $corpo);
        $this->assertStringNotContainsString('variacao-rosa', $corpo);
        $this->assertStringContainsString('/politica-de-entrega', $corpo);
    }

    public function test_home_traz_canonical_e_descricao_no_html_inicial(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<link rel="canonical"', false)
            ->assertSee('<meta name="description"', false);
    }
}
