<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-17 (variações de produto, estilo Shopee/Mercado
 * Livre): cadastro de variação de verdade (produto pai + filhos), em vez do
 * texto livre desconectado de antes. Caso real que motivou a feature: Ring
 * Light 8"/10" como 2 produtos sem relação nenhuma, mais um 3º duplicado
 * (anúncio duplicado no canal) sem foto e sem vínculo.
 */
class ProductVariationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_creating_a_product_with_a_parent_product_id_links_it_as_a_variation(): void
    {
        $parent = Product::factory()->create(['name' => 'Ring Light 10 Polegadas']);

        $response = $this->actingAs($this->admin())->post('/admin/produtos', [
            'name' => 'Ring Light 8 Polegadas',
            'category_id' => null,
            'variation' => '8 Polegadas',
            'price' => 39.90,
            'stock' => 10,
            'is_active' => true,
            'parent_product_id' => $parent->id,
        ]);

        $response->assertRedirect();

        $variation = Product::query()->where('name', 'Ring Light 8 Polegadas')->firstOrFail();
        $this->assertSame($parent->id, $variation->parent_product_id);
        // SKU/estoque continuam gerados normalmente, como um produto comum.
        $this->assertNotNull($variation->sku);
        $this->assertSame(10, $variation->stock);
    }

    public function test_create_page_prefills_category_brand_model_and_description_from_the_parent(): void
    {
        $parent = Product::factory()->create([
            'category_id' => null,
            'brand' => 'MarcaX',
            'model' => 'ModeloY',
            'description' => 'Descrição do pai',
        ]);

        $response = $this->actingAs($this->admin())->get("/admin/produtos/criar?parent_product_id={$parent->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('parent.id', $parent->id)
            ->where('parent.brand', 'MarcaX')
            ->where('parent.model', 'ModeloY')
            ->where('parent.description', 'Descrição do pai'));
    }

    /**
     * O caso real do produto #80: um 2º anúncio duplicado no canal gerou um
     * produto novo desconectado. A ferramenta "vincular produto existente"
     * resolve isso sem mexer no SKU/fotos/estoque já cadastrados nele.
     */
    public function test_attaching_an_existing_orphan_product_links_it_as_a_variation(): void
    {
        $parent = Product::factory()->create(['name' => 'Ring Light 8 Polegadas', 'sku' => 'RING-LIGTH-8-POLEGADAS-SOLO']);
        $orphan = Product::factory()->create(['name' => 'Ring Light 8" 20cm Completo', 'sku' => 'RING-LIGTH-8-POLEGADAS']);

        $response = $this->actingAs($this->admin())
            ->post("/admin/produtos/{$parent->id}/variacoes/vincular", ['variation_product_id' => $orphan->id]);

        $response->assertRedirect();
        $this->assertSame($parent->id, $orphan->fresh()->parent_product_id);
        // SKU não muda — a variação continua sendo o mesmo produto, só ganha o vínculo.
        $this->assertSame('RING-LIGTH-8-POLEGADAS', $orphan->fresh()->sku);
    }

    public function test_cannot_attach_a_product_that_already_has_a_parent(): void
    {
        $parentA = Product::factory()->create();
        $parentB = Product::factory()->create();
        $alreadyLinked = Product::factory()->create(['parent_product_id' => $parentA->id]);

        $response = $this->actingAs($this->admin())
            ->post("/admin/produtos/{$parentB->id}/variacoes/vincular", ['variation_product_id' => $alreadyLinked->id]);

        $response->assertRedirect();
        // Continua vinculado ao pai original, não foi movido.
        $this->assertSame($parentA->id, $alreadyLinked->fresh()->parent_product_id);
    }

    public function test_cannot_attach_a_product_that_already_has_children(): void
    {
        $newParent = Product::factory()->create();
        $existingParent = Product::factory()->create();
        Product::factory()->create(['parent_product_id' => $existingParent->id]);

        $response = $this->actingAs($this->admin())
            ->post("/admin/produtos/{$newParent->id}/variacoes/vincular", ['variation_product_id' => $existingParent->id]);

        $response->assertRedirect();
        $this->assertNull($existingParent->fresh()->parent_product_id);
    }

    public function test_detaching_a_variation_makes_it_standalone_again(): void
    {
        $parent = Product::factory()->create();
        $child = Product::factory()->create(['parent_product_id' => $parent->id]);

        $response = $this->actingAs($this->admin())->post("/admin/produtos/{$child->id}/variacoes/desvincular");

        $response->assertRedirect();
        $this->assertNull($child->fresh()->parent_product_id);
    }

    public function test_edit_page_shows_siblings_for_a_child_and_children_for_a_parent(): void
    {
        $parent = Product::factory()->create(['name' => 'Ring Light 10 Polegadas']);
        $child = Product::factory()->create(['name' => 'Ring Light 8 Polegadas', 'parent_product_id' => $parent->id]);

        $responseForParent = $this->actingAs($this->admin())->get("/admin/produtos/{$parent->id}/editar");
        $responseForParent->assertInertia(fn ($page) => $page
            ->where('variations.parent', null)
            ->where('variations.siblings.0.id', $child->id));

        $responseForChild = $this->actingAs($this->admin())->get("/admin/produtos/{$child->id}/editar");
        $responseForChild->assertInertia(fn ($page) => $page
            ->where('variations.parent.id', $parent->id)
            ->where('variations.siblings', []));
    }
}
