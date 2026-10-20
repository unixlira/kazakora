<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Ordem das avaliações (pedido 2026-10-20): com foto/vídeo primeiro e, dentro
 * de cada grupo, da mais recente para a mais antiga, sem olhar a nota. Inclui
 * as avaliações gravadas nas variações do produto.
 */
class AvaliacoesComMidiaPrimeiroTest extends TestCase
{
    use RefreshDatabase;

    private function avaliacao(Product $produto, int $nota, bool $foto = false, string $data = '2026-10-01 12:00:00'): Review
    {
        $review = Review::create(['user_id' => User::factory()->create()->id, 'product_id' => $produto->id, 'rating' => $nota, 'comment' => "Nota {$nota}"]);
        $review->forceFill(['created_at' => $data])->save();
        if ($foto) {
            $review->images()->create(['image_url' => 'https://exemplo.com/foto.jpg', 'position' => 0]);
        }

        return $review;
    }

    public function test_com_foto_primeiro_e_mais_recentes_antes(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $fotoAntiga = $this->avaliacao($produto, 5, foto: true, data: '2026-08-01 10:00:00');
        $semFotoRecente = $this->avaliacao($produto, 5, data: '2026-10-19 10:00:00');
        $fotoRecenteUmaEstrela = $this->avaliacao($produto, 1, foto: true, data: '2026-10-18 10:00:00');
        $semFotoAntiga = $this->avaliacao($produto, 4, data: '2026-09-01 10:00:00');

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reviews.0.id', $fotoRecenteUmaEstrela->id)
            ->where('reviews.1.id', $fotoAntiga->id)
            ->where('reviews.2.id', $semFotoRecente->id)
            ->where('reviews.3.id', $semFotoAntiga->id));
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
