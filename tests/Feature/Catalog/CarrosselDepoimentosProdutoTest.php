<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Carrossel de depoimentos na página do produto (pedido 2026-10-25): as mesmas
 * avaliações reais do carrossel do checkout, do anúncio pai e das variações.
 */
class CarrosselDepoimentosProdutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_do_produto_leva_as_avaliacoes_do_carrossel(): void
    {
        $pai = Product::factory()->create(['stock' => 5, 'is_active' => true]);
        $filha = Product::factory()->create(['stock' => 5, 'is_active' => true, 'parent_product_id' => $pai->id]);
        $outro = Product::factory()->create(['stock' => 5, 'is_active' => true]);
        $cliente = User::factory()->create(['name' => 'Carla Souza']);

        $boa = Review::create(['user_id' => $cliente->id, 'product_id' => $filha->id, 'rating' => 5, 'comment' => 'Chegou rápido e funciona muito bem.']);
        Review::create(['user_id' => $cliente->id, 'product_id' => $pai->id, 'rating' => 2, 'comment' => 'Não gostei do acabamento do produto.']);
        Review::create(['user_id' => $cliente->id, 'product_id' => $outro->id, 'rating' => 5, 'comment' => 'Produto de outro anúncio, não entra.']);

        $this->get('/produtos/'.$pai->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/ProductDetailV2', false)
            ->has('avaliacoesCarrossel', 1)
            ->where('avaliacoesCarrossel.0.id', $boa->id)
            ->where('avaliacoesCarrossel.0.nota', 5));
    }

    public function test_produto_sem_avaliacao_manda_carrossel_vazio(): void
    {
        $produto = Product::factory()->create(['stock' => 5, 'is_active' => true]);

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('avaliacoesCarrossel', []));
    }
}
