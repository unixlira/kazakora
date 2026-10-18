<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pedido 2026-10-18: Desconto Site em % ou em valor fixo (R$), um ou outro. */
class ProductDescontoSiteTipoTest extends TestCase
{
    use RefreshDatabase;

    private function salvar(Product $product, array $dados): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->put("/admin/produtos/{$product->id}", array_merge([
                'name' => $product->name,
                'price' => 100,
                'is_active' => true,
            ], $dados))
            ->assertRedirect();
    }

    public function test_desconto_site_em_valor_fixo_fica_gravado(): void
    {
        $product = Product::factory()->create(['price' => 100]);

        $this->salvar($product, ['discount_percentage' => null, 'discount_amount' => 15]);

        $product->refresh();
        $this->assertSame('15.00', $product->discount_amount);
        $this->assertNull($product->discount_percentage);
        $this->assertSame(85.0, $product->precoSemOferta());
    }

    public function test_percentual_preenchido_tira_o_valor_fixo(): void
    {
        $product = Product::factory()->create(['price' => 100, 'discount_amount' => 15]);

        $this->salvar($product, ['discount_percentage' => 10, 'discount_amount' => 15]);

        $product->refresh();
        $this->assertSame('10.0000', $product->discount_percentage);
        $this->assertNull($product->discount_amount);
    }
}
