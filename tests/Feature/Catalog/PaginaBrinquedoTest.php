<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Support\LayoutDaPagina;
use App\Modules\Catalog\Support\PaginaBrinquedo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Página de brinquedo (pedido do Lira 24/11/2026): produto da categoria de
 * brinquedos abre com layout "brinquedo"; o resto continua no "padrao". O
 * produto (ou o pai) pode forçar um dos dois em products.layout_pagina.
 */
class PaginaBrinquedoTest extends TestCase
{
    use RefreshDatabase;

    private function categoria(string $slug): Category
    {
        return Category::factory()->create(['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))]);
    }

    public function test_brinquedo_sem_conteudo_continua_no_layout_padrao(): void
    {
        // Sem conteúdo próprio nem do anúncio a página nova ficaria vazia: fica a padrão.
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id]);

        $this->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('layout', 'padrao'));
    }

    public function test_brinquedo_abre_com_layout_brinquedo(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id,
            'pagina_brinquedo' => ['titulo' => 'Diversão longe das telas']]);

        $this->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)
                ->where('product.id', $produto->id)
                ->where('layout', 'brinquedo'));
    }

    public function test_brinquedo_so_com_conteudo_do_anuncio_abre_com_layout_brinquedo(): void
    {
        // Pedido do Lira 25/11/2026: todo brinquedo ativo abre na página KoraKids; sem pagina_brinquedo,
        // as seções usam o conteúdo do anúncio como reserva.
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id]);
        ProductAdContent::create([
            'product_id' => $produto->id,
            'fonte' => ProductAdContent::FONTE_GEMINI,
            'destaques' => ['Funciona sem pilhas'],
            'blocos' => [['titulo' => 'Diversão longe das telas', 'texto' => 'Brincadeira de verdade.', 'imagem' => 0]],
        ]);

        $this->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('layout', 'brinquedo')
                ->where('paginaBrinquedo', null)
                ->where('product.ad_content.blocos.0.titulo', 'Diversão longe das telas'));
    }

    public function test_variacao_de_brinquedo_usa_o_anuncio_do_pai(): void
    {
        $categoria = $this->categoria('brinquedos-e-games');
        $pai = Product::factory()->create(['is_active' => true, 'category_id' => $categoria->id]);
        $variacao = Product::factory()->create(['is_active' => true, 'category_id' => $categoria->id, 'parent_product_id' => $pai->id]);
        ProductAdContent::create([
            'product_id' => $pai->id,
            'fonte' => ProductAdContent::FONTE_GEMINI,
            'blocos' => [['titulo' => 'Corrida na sala', 'texto' => 'Pista que monta em minutos.']],
        ]);

        $this->assertSame(LayoutDaPagina::BRINQUEDO, LayoutDaPagina::para($variacao->fresh()));
    }

    public function test_conteudo_aceita_foto_do_storage_fora_da_galeria(): void
    {
        $pagina = [
            'porque' => ['titulo' => 'Por que adora', 'imagem' => 'products/192/ia-08-abc.jpg'],
            'ensina' => ['titulo' => 'Ensina', 'imagem' => 2],
            'detalhes' => ['titulo' => 'Detalhes', 'imagem' => '../../.env'],
        ];

        $this->assertSame(['foto com caminho inválido: ../../.env'], PaginaBrinquedo::problemas($pagina, [], 3));
        $this->assertSame(['products/192/ia-08-abc.jpg', '../../.env'], PaginaBrinquedo::fotosDoStorage($pagina));
    }

    public function test_comando_recusa_foto_que_nao_esta_no_storage(): void
    {
        Storage::fake('public');
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id]);
        $arquivo = database_path("paginas-brinquedo/{$produto->slug}.json");
        file_put_contents($arquivo, json_encode(['titulo' => 'Teste', 'porque' => ['titulo' => 'Por que', 'imagem' => "products/{$produto->id}/ia-01.jpg"]]));

        try {
            $this->artisan('brinquedo:pagina', ['--slug' => [$produto->slug]])->assertFailed();
            $this->assertNull($produto->fresh()->pagina_brinquedo);

            Storage::disk('public')->put("products/{$produto->id}/ia-01.jpg", 'x');
            $this->artisan('brinquedo:pagina', ['--slug' => [$produto->slug]])->assertSuccessful();
            $this->assertSame('Teste', $produto->fresh()->pagina_brinquedo['titulo']);
        } finally {
            @unlink($arquivo);
        }
    }

    public function test_produto_comum_continua_no_layout_padrao(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('ferramentas-e-automotivo')->id]);

        $this->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)
                ->where('product.id', $produto->id)
                ->where('layout', 'padrao'));
    }

    public function test_produto_pode_forcar_o_layout(): void
    {
        $ferramenta = Product::factory()->create([
            'is_active' => true, 'category_id' => $this->categoria('ferramentas-e-automotivo')->id, 'layout_pagina' => 'brinquedo',
        ]);
        $brinquedoPadrao = Product::factory()->create([
            'is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id, 'layout_pagina' => 'padrao',
        ]);

        $this->get("/produtos/{$ferramenta->slug}")->assertInertia(fn ($page) => $page->where('layout', 'brinquedo'));
        $this->get("/produtos/{$brinquedoPadrao->slug}")->assertInertia(fn ($page) => $page->where('layout', 'padrao'));
    }

    public function test_variacao_segue_o_layout_do_pai_e_leva_os_kits(): void
    {
        $categoria = $this->categoria('gadgets');
        $pai = Product::factory()->create(['is_active' => true, 'category_id' => $categoria->id, 'layout_pagina' => 'brinquedo', 'variation' => 'Livro Falante', 'price' => 152]);
        $kit = Product::factory()->create([
            'is_active' => true, 'category_id' => $categoria->id, 'parent_product_id' => $pai->id,
            'variation' => 'Livro Falante + Lousa Mágica LED', 'price' => 184.90,
        ]);

        $this->get("/produtos/{$kit->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('layout', 'brinquedo')
                ->where('variations.0.id', $pai->id)
                ->where('variations.0.variation', 'Livro Falante'));

        $this->get("/produtos/{$pai->slug}")
            ->assertInertia(fn ($page) => $page->where('layout', 'brinquedo')
                ->where('variations.0.variation', 'Livro Falante + Lousa Mágica LED')
                ->missing('product.cost_price'));
    }

    public function test_versao_1_nao_muda(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id]);

        $this->get("/produtos/{$produto->slug}?v=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetail', false));
    }

    public function test_som_da_pagina_de_brinquedo_esta_publicado_e_leve(): void
    {
        $arquivo = public_path('sons/aeee.mp3');

        $this->assertFileExists($arquivo);
        $this->assertLessThan(60 * 1024, filesize($arquivo));
    }

    public function test_avaliacoes_de_outros_produtos_entram_com_etiqueta(): void
    {
        $categoria = $this->categoria('brinquedos-e-games');
        $livro = Product::factory()->create(['is_active' => true, 'category_id' => $categoria->id]);
        $kit = Product::factory()->create([
            'is_active' => true, 'category_id' => $categoria->id,
            'avaliacoes_de' => [['id' => $livro->id, 'rotulo' => 'Livro Falante']],
            'pagina_brinquedo' => ['titulo' => 'Aprende brincando'],
        ]);
        \App\Modules\Catalog\Models\Review::query()->create(['product_id' => $livro->id, 'rating' => 5, 'comment' => 'Muito bom', 'reviewer_name' => 'Ana Souza']);

        $this->get("/produtos/{$kit->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reviewsResumo.total', 1)
                ->where('reviews.0.produto_rotulo', 'Livro Falante')
                ->missing('product.avaliacoes_de'));
    }

    public function test_card_sabe_que_a_pagina_e_de_brinquedo(): void
    {
        // 27/11/2026: o card do livro mostrava o preço com cupom (93,09) e a página de brinquedo o sem
        // cupom (97,99). O card recebe layout_card para mostrar o preço do mesmo jeito da página.
        $brinquedos = $this->categoria('brinquedos-e-games');
        $livro = Product::factory()->create(['is_active' => true, 'stock' => 5, 'category_id' => $brinquedos->id,
            'pagina_brinquedo' => ['titulo' => 'Diversão longe das telas']]);
        $semConteudo = Product::factory()->create(['is_active' => true, 'stock' => 5, 'category_id' => $brinquedos->id]);
        $trena = Product::factory()->create(['is_active' => true, 'stock' => 5, 'category_id' => $this->categoria('ferramentas')->id]);

        $cards = collect($this->get('/?todos=1')->viewData('page')['props']['products']['data'])->keyBy('id');

        $this->assertSame('brinquedo', $cards[$livro->id]['layout_card']);
        $this->assertNull($cards[$semConteudo->id]['layout_card']);
        $this->assertNull($cards[$trena->id]['layout_card']);
    }
}
