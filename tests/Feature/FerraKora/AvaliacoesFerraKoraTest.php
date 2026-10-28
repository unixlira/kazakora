<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Models\ReviewImage;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avaliações do KazaKora nos produtos do FerraKora (pedido 2026-10-28):
 * nota e total na lista, avaliações do pai + variações na página.
 */
class AvaliacoesFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private function publicado(): array
    {
        $pai = Product::factory()->create(['sku' => 'FER-TRE-5M', 'name' => 'Trena 5m Cor Amarela', 'variation' => 'Cor Amarela', 'price' => 39.90, 'stock' => 8, 'is_active' => true]);
        $filho = Product::factory()->create(['sku' => 'FER-TRE-5M-PR', 'name' => 'Trena 5m Cor Preta', 'variation' => 'Cor Preta', 'parent_product_id' => $pai->id, 'price' => 39.90, 'stock' => 3, 'is_active' => true]);
        FerraKoraProduto::create(['product_id' => $pai->id, 'sku' => 'FER-TRE-5M', 'ordem' => 1, 'ativo' => true]);

        return [$pai, $filho];
    }

    public function test_lista_leva_nota_e_total_do_pai_com_variacoes(): void
    {
        [$pai, $filho] = $this->publicado();
        Review::create(['product_id' => $pai->id, 'rating' => 5, 'comment' => 'Ótima', 'reviewer_name' => 'Ana']);
        Review::create(['product_id' => $filho->id, 'rating' => 4, 'comment' => 'Boa', 'reviewer_name' => 'Rui']);

        $this->getJson('/api/ferrakora/produtos')->assertOk()
            ->assertJsonPath('products.0.reviewsAnalytics.count', 2)
            ->assertJsonPath('products.0.reviewsAnalytics.average_rating', 4.5);
    }

    public function test_sem_avaliacao_nota_vazia(): void
    {
        $this->publicado();

        $this->getJson('/api/ferrakora/produtos')->assertOk()
            ->assertJsonPath('products.0.reviewsAnalytics.count', 0)
            ->assertJsonPath('products.0.reviewsAnalytics.average_rating', null);
    }

    public function test_pagina_traz_com_midia_primeiro_sem_canal_nem_resposta(): void
    {
        [$pai, $filho] = $this->publicado();
        $antiga = Review::create(['product_id' => $pai->id, 'rating' => 5, 'comment' => 'Com foto', 'reviewer_name' => 'Ana', 'uf' => 'SP', 'channel' => 'shopee', 'seller_reply' => 'Obrigado!']);
        $antiga->forceFill(['created_at' => now()->subDays(10)])->save();
        ReviewImage::create(['review_id' => $antiga->id, 'image_url' => 'https://kazakora.com/storage/a.jpg', 'position' => 0]);
        ReviewImage::create(['review_id' => $antiga->id, 'image_url' => 'https://kazakora.com/storage/a.mp4', 'position' => 1]);
        Review::create(['product_id' => $filho->id, 'rating' => 3, 'comment' => 'Sem foto', 'reviewer_name' => 'Rui', 'ocultar_nome' => true]);

        $resposta = $this->getJson('/api/ferrakora/produtos/kz-'.$pai->id.'/avaliacoes')->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('por_nota.5', 1)
            ->assertJsonPath('por_nota.3', 1)
            ->assertJsonPath('avaliacoes.0.texto', 'Com foto')
            ->assertJsonPath('avaliacoes.0.uf', 'SP')
            ->assertJsonPath('avaliacoes.0.midias.0.tipo', 'imagem')
            ->assertJsonPath('avaliacoes.0.midias.1.tipo', 'video')
            ->assertJsonPath('avaliacoes.1.nome', 'Cliente FerraKora');

        $this->assertStringNotContainsString('shopee', $resposta->getContent());
        $this->assertStringNotContainsString('Obrigado!', $resposta->getContent());
    }

    public function test_produto_fora_do_site_da_404(): void
    {
        $fora = Product::factory()->create(['sku' => 'FORA', 'is_active' => true]);

        $this->getJson('/api/ferrakora/produtos/kz-'.$fora->id.'/avaliacoes')->assertNotFound();
    }
}
