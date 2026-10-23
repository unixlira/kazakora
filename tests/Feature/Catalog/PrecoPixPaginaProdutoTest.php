<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Support\DescontoPix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Página de produto mostra "R$ X no Pix" com a mesma conta do checkout
 * (pedido 2026-10-23): o backend manda preco_pix pronto pelo DescontoPix.
 */
class PrecoPixPaginaProdutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_preco_pix_segue_a_conta_do_checkout(): void
    {
        $percentual = Product::factory()->create(['price' => 197.9, 'checkout_discount_percentage' => 5, 'checkout_discount_amount' => null]);
        $fixo = Product::factory()->create(['price' => 100, 'checkout_discount_percentage' => null, 'checkout_discount_amount' => 7]);
        $sem = Product::factory()->create(['price' => 100, 'checkout_discount_percentage' => null, 'checkout_discount_amount' => null]);

        // 197,90 - round(197,90 x 5%) = 197,90 - 9,90 (9,895 arredonda no centavo).
        $preco = (float) $percentual->final_price;
        $this->assertSame(round($preco - DescontoPix::desconto($preco, 5), 2), DescontoPix::precoPix($percentual));
        $this->assertSame(round((float) $fixo->final_price - 7, 2), DescontoPix::precoPix($fixo));
        $this->assertNull(DescontoPix::precoPix($sem));

        // Kit: 2 unidades a 90,00 com 5% = 180,00 - 9,00.
        $this->assertSame(171.0, DescontoPix::precoPix($percentual, 90.0, 2));
    }

    public function test_pagina_do_produto_e_das_variacoes_levam_o_preco_pix(): void
    {
        $pai = Product::factory()->create(['price' => 120, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 5]);
        $filha = Product::factory()->create(['price' => 80, 'stock' => 5, 'is_active' => true, 'parent_product_id' => $pai->id, 'checkout_discount_percentage' => 10]);

        $this->get('/produtos/'.$pai->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/ProductDetailV2', false)
            ->where('product.preco_pix', fn ($valor) => (float) $valor === DescontoPix::precoPix($pai->fresh()))
            ->where('variations.0.id', $filha->id)
            ->where('variations.0.preco_pix', fn ($valor) => (float) $valor === DescontoPix::precoPix($filha->fresh())));
    }
}
