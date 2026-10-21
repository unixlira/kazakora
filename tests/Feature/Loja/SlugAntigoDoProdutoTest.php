<?php

namespace Tests\Feature\Loja;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductSlugAntigo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Link antigo do produto renomeado responde 301 (Lira, 2026-10-20). */
class SlugAntigoDoProdutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_renomear_guarda_o_slug_antigo_e_o_link_velho_redireciona(): void
    {
        $produto = Product::factory()->create([
            'slug' => 'parafusadeira-furadeira-36v-bivolt-2-bateria-maleta-kicool',
            'is_active' => true,
        ]);

        $produto->update(['slug' => 'parafusadeira-furadeira-48v-2-baterias-maleta-carregador-bivolt']);

        $this->assertSame($produto->id, ProductSlugAntigo::where('slug', 'parafusadeira-furadeira-36v-bivolt-2-bateria-maleta-kicool')->value('product_id'));

        $this->get('/produtos/parafusadeira-furadeira-36v-bivolt-2-bateria-maleta-kicool')
            ->assertStatus(301)
            ->assertRedirect('/produtos/parafusadeira-furadeira-48v-2-baterias-maleta-carregador-bivolt');
    }

    public function test_voltar_ao_slug_antigo_tira_o_redirecionamento(): void
    {
        $produto = Product::factory()->create(['slug' => 'nome-a', 'is_active' => true]);

        $produto->update(['slug' => 'nome-b']);
        $produto->update(['slug' => 'nome-a']);

        $this->assertSame(['nome-b'], ProductSlugAntigo::pluck('slug')->all());
    }

    public function test_slug_que_nunca_existiu_continua_404(): void
    {
        $this->get('/produtos/produto-que-nunca-existiu-xyz')->assertNotFound();
    }
}
