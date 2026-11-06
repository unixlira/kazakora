<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Models\ReviewImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Lentidão da página de produto (tarefa 3878, 06/11): a página traz só as 20
 * primeiras avaliações com o resumo contado no servidor, o resto vem de 12 em
 * 12, e a foto de avaliação ganha miniatura.
 */
class PaginaProdutoRapidaTest extends TestCase
{
    use RefreshDatabase;

    private function avaliacoes(Product $produto, int $quantidade, int $nota): void
    {
        for ($i = 0; $i < $quantidade; $i++) {
            Review::create(['user_id' => User::factory()->create()->id, 'product_id' => $produto->id, 'rating' => $nota, 'comment' => "Nota {$nota}"]);
        }
    }

    public function test_pagina_traz_20_avaliacoes_e_o_resumo_de_todas(): void
    {
        $pai = Product::factory()->create(['is_active' => true]);
        $variacao = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id]);
        $this->avaliacoes($pai, 20, 5);
        $this->avaliacoes($variacao, 5, 3);

        $this->get('/produtos/'.$pai->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reviews', 20)
            ->where('product.reviews_count', 25)
            ->where('product.reviews_avg_rating', 4.6)
            ->where('reviewsResumo.total', 25)
            ->where('reviewsResumo.estrelas.5', 20)
            ->where('reviewsResumo.estrelas.3', 5)
            ->where('reviewsResumo.estrelas.1', 0));
    }

    public function test_carregar_mais_traz_as_proximas_na_mesma_ordem(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->avaliacoes($produto, 30, 5);

        $primeiras = collect();
        $this->get('/produtos/'.$produto->slug)->assertInertia(function (AssertableInertia $page) use (&$primeiras) {
            $primeiras = collect($page->toArray()['props']['reviews'])->pluck('id');

            return $page;
        });

        $proximas = $this->getJson('/produtos/'.$produto->slug.'/avaliacoes?pular=20')
            ->assertOk()
            ->assertJsonCount(10, 'avaliacoes')
            ->json('avaliacoes.*.id');

        $this->assertCount(20, $primeiras);
        $this->assertEmpty($primeiras->intersect($proximas));
        $this->assertCount(30, $primeiras->concat($proximas)->unique());
    }

    public function test_avaliacao_nova_aparece_mesmo_com_cache(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->avaliacoes($produto, 1, 5);
        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page->where('reviewsResumo.total', 1));

        $this->avaliacoes($produto, 1, 4);

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reviewsResumo.total', 2)
            ->has('reviews', 2));
    }

    public function test_produto_fora_da_loja_nao_lista_avaliacoes(): void
    {
        $produto = Product::factory()->create(['is_active' => false]);

        $this->getJson('/produtos/'.$produto->slug.'/avaliacoes')->assertNotFound();
    }

    public function test_comando_gera_miniatura_das_fotos_antigas_e_pula_video(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avaliacoes/clientes/foto.jpg', UploadedFile::fake()->image('foto.jpg', 800, 1080)->getContent());
        $review = Review::create(['user_id' => User::factory()->create()->id, 'product_id' => Product::factory()->create()->id, 'rating' => 5]);

        // Fotos gravadas antes da coluna existir: sem miniatura.
        $foto = ReviewImage::query()->forceCreate(['review_id' => $review->id, 'image_url' => 'https://kazakora.com/storage/avaliacoes/clientes/foto.jpg', 'thumb_url' => null, 'position' => 0]);
        $foto->forceFill(['thumb_url' => null])->saveQuietly();
        $video = ReviewImage::query()->forceCreate(['review_id' => $review->id, 'image_url' => 'https://kazakora.com/storage/avaliacoes/clientes/video.mp4', 'position' => 1]);

        $this->artisan('avaliacoes:miniaturas')->assertSuccessful();

        $this->assertSame('https://kazakora.com/storage/avaliacoes/clientes/minis/foto.webp', $foto->fresh()->thumb_url);
        $this->assertTrue(Storage::disk('public')->exists('avaliacoes/clientes/minis/foto.webp'));
        $this->assertSame('', $video->fresh()->thumb_url);
        $this->assertTrue(Storage::disk('public')->exists('avaliacoes/clientes/foto.jpg'));
    }
}
