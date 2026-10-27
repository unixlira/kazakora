<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Carrossel de depoimentos só no checkout (Lira em 2026-10-26): a página do produto
 * não leva mais o carrossel em cima do COMPRAR AGORA.
 */
class CarrosselDepoimentosProdutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_do_produto_nao_leva_o_carrossel(): void
    {
        $produto = Product::factory()->create(['stock' => 5, 'is_active' => true]);
        $cliente = User::factory()->create(['name' => 'Carla Souza']);
        Review::create(['user_id' => $cliente->id, 'product_id' => $produto->id, 'rating' => 5, 'comment' => 'Chegou rápido e funciona muito bem.']);

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/ProductDetailV2', false)
            ->missing('avaliacoesCarrossel'));
    }
}
