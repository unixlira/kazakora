<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SEO só do site: título, meta description e tags próprios; o nome (marketplaces, h1) não muda. */
class SeoDoProdutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_seo_a_pagina_usa_nome_e_descricao_de_sempre(): void
    {
        $product = Product::factory()->create(['name' => 'Furadeira X', 'description' => 'Descrição comum do produto.', 'is_active' => true]);

        $html = $this->get("/produtos/{$product->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('KazaKora - Furadeira X</title>', $html);
        $this->assertStringContainsString('content="Descrição comum do produto."', $html);
        $this->assertStringNotContainsString('name="keywords"', $html);
        $this->assertStringNotContainsString('"keywords"', $html);
    }

    public function test_com_seo_a_pagina_usa_titulo_descricao_e_tags(): void
    {
        $product = Product::factory()->create([
            'name' => 'Furadeira X',
            'description' => 'Descrição comum do produto.',
            'is_active' => true,
            'seo_title' => 'Furadeira X Amarela com Maleta',
            'seo_description' => 'Meta própria do site.',
            'tags' => ['furadeira amarela', 'kit com maleta'],
        ]);

        $html = $this->get("/produtos/{$product->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('KazaKora - Furadeira X Amarela com Maleta</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Meta própria do site.">', $html);
        $this->assertStringContainsString('<meta name="keywords" content="furadeira amarela, kit com maleta">', $html);
        $this->assertStringContainsString('"keywords":"furadeira amarela, kit com maleta"', $html);
        // O nome do anúncio segue intacto nos dados da página.
        $this->assertSame('Furadeira X', $product->fresh()->name);
    }

    public function test_tags_entram_na_busca_do_site(): void
    {
        $com = Product::factory()->create(['name' => 'Parafusadeira Z', 'is_active' => true, 'price' => 100, 'tags' => ['furadeira rosa']]);
        Product::factory()->create(['name' => 'Trena de Aço', 'is_active' => true, 'price' => 10]);

        $ids = collect($this->get('/?search=rosa')->viewData('page')['props']['products']['data'])->pluck('id')->all();

        $this->assertSame([$com->id], $ids);
    }

    public function test_admin_salva_e_limpa_os_campos_seo(): void
    {
        $product = Product::factory()->create(['price' => 100]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $base = ['name' => $product->name, 'price' => 100, 'is_active' => true];

        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", $base + [
            'seo_title' => '  Título SEO  ',
            'seo_description' => 'Descrição SEO',
            'tags' => 'a b, c ,, a b, d',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('Título SEO', $product->seo_title);
        $this->assertSame('Descrição SEO', $product->seo_description);
        $this->assertSame(['a b', 'c', 'd'], $product->tags);

        // Salvar sem mandar os campos não apaga o SEO.
        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", $base)->assertRedirect();
        $this->assertSame('Título SEO', $product->fresh()->seo_title);

        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", $base + ['seo_title' => '', 'seo_description' => '', 'tags' => ''])->assertRedirect();
        $product->refresh();
        $this->assertNull($product->seo_title);
        $this->assertNull($product->seo_description);
        $this->assertNull($product->tags);
    }
}
