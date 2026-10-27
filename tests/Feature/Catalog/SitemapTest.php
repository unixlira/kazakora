<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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

    public function test_link_da_home_compartilhado_leva_a_logo_oficial(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<meta property="og:title" content="'.config('app.name').'">', false)
            ->assertSee('<meta property="og:image" content="'.url('/images/marca/og-kazakora.png').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);

        $this->assertFileExists(public_path('images/marca/og-kazakora.png'));
    }

    public function test_pagina_de_produto_traz_titulo_descricao_e_dados_estruturados_no_html_inicial(): void
    {
        Product::factory()->create([
            'slug' => 'ring-light-10',
            'name' => 'Ring Light 10 Polegadas',
            'description' => '<p>Iluminação   para vídeos</p>',
            'is_active' => true,
        ]);

        $this->get('/produtos/ring-light-10')->assertOk()
            ->assertSee('<title inertia>KazaKora - Ring Light 10 Polegadas</title>', false)
            ->assertSee('<meta name="description" content="Iluminação para vídeos">', false)
            ->assertSee('"@type":"Product"', false)
            ->assertSee('"priceCurrency":"BRL"', false);
    }

    public function test_video_do_produto_vai_com_miniatura_na_pagina_e_no_sitemap(): void
    {
        Cache::forget('sitemap.xml');
        $produto = Product::factory()->create([
            'slug' => 'controle-yihan',
            'name' => 'Controle Yihan',
            'description' => '<p>Controle com fio</p>',
            'is_active' => true,
            'video_path' => 'products/1/video/demo.mp4',
            'video_duration_seconds' => 22,
        ]);
        $produto->images()->create(['path' => 'products/1/foto.jpg', 'position' => 0, 'is_primary' => true]);
        Product::factory()->create(['slug' => 'sem-foto', 'is_active' => true, 'video_path' => 'products/2/video/x.mp4']);

        $this->get('/produtos/controle-yihan')->assertOk()
            ->assertSee('"@type":"VideoObject"', false)
            ->assertSee('"thumbnailUrl":["'.asset('storage/products/1/foto.jpg').'"]', false)
            ->assertSee('"duration":"PT22S"', false)
            ->assertSee('"uploadDate":"', false);

        $corpo = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"', $corpo);
        $this->assertStringContainsString('<video:thumbnail_loc>'.asset('storage/products/1/foto.jpg').'</video:thumbnail_loc>', $corpo);
        $this->assertStringContainsString('<video:content_loc>'.asset('storage/products/1/video/demo.mp4').'</video:content_loc>', $corpo);
        $this->assertSame(1, substr_count($corpo, '<video:video>'), 'vídeo sem foto não entra (o Google recusaria sem miniatura)');
        $this->assertSame(false, simplexml_load_string($corpo) === false);
    }

    public function test_homolog_responde_noindex_e_dominio_oficial_nao(): void
    {
        $this->get('https://kazakora.devlira.com.br/')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get('https://kazakora.com/')->assertOk()
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_link_antigo_da_nuvemshop_redireciona_para_o_produto_atual(): void
    {
        Product::factory()->create(['slug' => 'lanterna-de-cabeca-profissional-triplo-led-recarregavel', 'is_active' => true]);

        $this->get('/produtos/lanterna-de-cabeca-profissional-triplo-led-recarregavel-123456/')
            ->assertStatus(301)
            ->assertRedirect('/produtos/lanterna-de-cabeca-profissional-triplo-led-recarregavel');

        $this->get('/produtos/lanterna-cabeca-triplo-led-recarregavel-987/')
            ->assertStatus(301)
            ->assertRedirect('/produtos/lanterna-de-cabeca-profissional-triplo-led-recarregavel');

        $this->get('/produtos/geladeira-frost-free-555/')->assertNotFound();
    }

    public function test_link_antigo_nao_vai_para_produto_diferente_so_por_conectivos(): void
    {
        Product::factory()->create(['slug' => 'mini-camera-a9-wi-fi-hd-com-sensor-de-movimento-e-gravacao-em-cartao-de-memoria', 'is_active' => true]);

        $this->get('/produtos/luminaria-de-parede-com-sensor-de-movimento/?variant=1480886036')->assertNotFound();
    }

    public function test_variacao_aponta_canonica_para_o_produto_pai(): void
    {
        $pai = Product::factory()->create(['slug' => 'garrafa-termica', 'is_active' => true]);
        Product::factory()->create(['slug' => 'garrafa-termica-rosa', 'is_active' => true, 'parent_product_id' => $pai->id]);

        $this->get('/produtos/garrafa-termica-rosa')->assertOk()
            ->assertSee('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/produtos/garrafa-termica">', false);
    }

    public function test_carrinho_e_busca_levam_noindex_no_dominio_oficial(): void
    {
        $this->get('https://kazakora.com/carrinho')->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->get('https://kazakora.com/?search=lanterna')->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->get('https://kazakora.com/?categoria=casa')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_link_do_produto_no_whatsapp_leva_foto_leve_ou_a_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/leve.jpg', str_repeat('a', 50 * 1024));
        Storage::disk('public')->put('products/pesada.png', str_repeat('a', 900 * 1024));
        Storage::disk('public')->put('products/medias/pesada.webp', str_repeat('a', 40 * 1024));

        $leve = Product::factory()->create(['slug' => 'foto-leve', 'is_active' => true]);
        $leve->images()->create(['path' => 'products/leve.jpg', 'medium_path' => 'products/medias/leve.webp', 'position' => 0, 'is_primary' => true]);

        $pesada = Product::factory()->create(['slug' => 'foto-pesada', 'is_active' => true]);
        $pesada->images()->create(['path' => 'products/pesada.png', 'medium_path' => 'products/medias/pesada.webp', 'position' => 0, 'is_primary' => true]);

        Product::factory()->create(['slug' => 'sem-foto', 'is_active' => true]);

        $this->get('/produtos/foto-leve')->assertOk()
            ->assertSee('<meta property="og:image" content="'.url('/storage/products/leve.jpg').'">', false);
        $this->get('/produtos/foto-pesada')->assertOk()
            ->assertSee('<meta property="og:image" content="'.url('/storage/products/medias/pesada.webp').'">', false);
        $this->get('/produtos/sem-foto')->assertOk()
            ->assertSee('<meta property="og:image" content="'.url('/images/marca/og-kazakora.png').'">', false);
    }
}
