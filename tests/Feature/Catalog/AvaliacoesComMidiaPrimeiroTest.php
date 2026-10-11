<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Ordem das avaliações (pedido 2026-10-11): mais estrelas primeiro; dentro da
 * mesma nota, com foto antes; a última é a de 1 estrela. Inclui as avaliações
 * gravadas nas variações do produto.
 */
class AvaliacoesComMidiaPrimeiroTest extends TestCase
{
    use RefreshDatabase;

    private function avaliacao(Product $produto, int $nota, bool $foto = false): Review
    {
        $review = Review::create(['user_id' => User::factory()->create()->id, 'product_id' => $produto->id, 'rating' => $nota, 'comment' => "Nota {$nota}"]);
        if ($foto) {
            $review->images()->create(['image_url' => 'https://exemplo.com/foto.jpg', 'position' => 0]);
        }

        return $review;
    }

    public function test_mais_estrelas_primeiro_e_com_foto_antes_dentro_da_nota(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $um = $this->avaliacao($produto, 1, foto: true);
        $quatroComFoto = $this->avaliacao($produto, 4, foto: true);
        $cincoSemFoto = $this->avaliacao($produto, 5);
        $cincoComFoto = $this->avaliacao($produto, 5, foto: true);

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reviews.0.id', $cincoComFoto->id)
            ->where('reviews.1.id', $cincoSemFoto->id)
            ->where('reviews.2.id', $quatroComFoto->id)
            ->where('reviews.3.id', $um->id));
    }

    public function test_inclui_avaliacoes_das_variacoes(): void
    {
        $pai = Product::factory()->create(['is_active' => true]);
        $variacao = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id]);
        $this->avaliacao($pai, 5);
        $this->avaliacao($variacao, 4);

        $this->get('/produtos/'.$pai->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reviews', 2)
            ->where('product.reviews_count', 2));
    }
}
