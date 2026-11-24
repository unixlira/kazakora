<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Models\ReviewImage;
use App\Modules\Checkout\Support\AvaliacoesDoCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carrossel de depoimentos do checkout (pedido do Lira 2026-11-23): no máximo
 * 4, sem rosto de banco de imagens, com círculo de iniciais, a foto que o
 * cliente mandou e o selo de compra verificada (reviews.order_id).
 */
class AvaliacoesDoCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function avaliar(Product $produto, string $nome, array $dados = []): Review
    {
        return Review::create([
            'user_id' => User::factory()->create(['name' => $nome])->id,
            'product_id' => $produto->id,
            'rating' => 5,
            'comment' => 'Chegou rápido e funciona muito bem, recomendo.',
        ] + $dados);
    }

    public function test_no_maximo_4_avaliacoes(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        foreach (range(1, 7) as $n) {
            $this->avaliar($produto, 'Maria '.$n);
        }

        $avaliacoes = app(AvaliacoesDoCheckout::class)->paraProdutos([$produto->id]);

        $this->assertSame(4, AvaliacoesDoCheckout::LIMITE);
        $this->assertCount(4, $avaliacoes);
    }

    public function test_sem_rosto_de_banco_e_com_iniciais(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->avaliar($produto, 'Carla Souza');

        $avaliacao = app(AvaliacoesDoCheckout::class)->paraProdutos([$produto->id])[0];

        $this->assertSame('Carla Souza', $avaliacao['nome']);
        $this->assertSame('CS', $avaliacao['iniciais']);
        $this->assertSame(AvaliacoesDoCheckout::cor('Carla Souza'), $avaliacao['cor']);
        $this->assertStringNotContainsString('avatares', $avaliacao['avatar']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avaliacao['avatar']);
        $this->assertNull($avaliacao['foto']);
        $this->assertFalse($avaliacao['verificada']);
    }

    public function test_compra_verificada_quando_a_avaliacao_tem_pedido(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->avaliar($produto, 'Bruno Dias')->forceFill(['order_id' => 987])->save();

        $avaliacao = app(AvaliacoesDoCheckout::class)->paraProdutos([$produto->id])[0];

        $this->assertTrue($avaliacao['verificada']);
        $this->assertArrayNotHasKey('order_id', $avaliacao);
    }

    public function test_foto_do_cliente_em_miniatura_e_video_fica_de_fora(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $review = $this->avaliar($produto, 'Ana Lima');
        ReviewImage::create(['review_id' => $review->id, 'image_url' => 'https://cdn.exemplo.test/video.mp4', 'position' => 0]);
        ReviewImage::create(['review_id' => $review->id, 'image_url' => 'https://cdn.exemplo.test/foto.jpg', 'thumb_url' => 'https://cdn.exemplo.test/minis/foto.jpg', 'position' => 1]);

        $avaliacao = app(AvaliacoesDoCheckout::class)->paraProdutos([$produto->id])[0];

        $this->assertSame('https://cdn.exemplo.test/minis/foto.jpg', $avaliacao['foto']);
    }
}
