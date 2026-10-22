<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Estrelas do card contam as avaliações das variações (pedido 2026-10-20). */
class EstrelasDaVariacaoNoCardTest extends TestCase
{
    use RefreshDatabase;

    private function avaliar(Product $produto, int $nota): void
    {
        Review::create(['user_id' => User::factory()->create()->id, 'product_id' => $produto->id, 'rating' => $nota, 'comment' => "Nota {$nota}"]);
    }

    private function cards()
    {
        return collect($this->get('/?todos=1')->viewData('page')['props']['products']['data'])->keyBy('id');
    }

    public function test_card_do_pai_mostra_a_media_das_variacoes(): void
    {
        $pai = Product::factory()->create(['is_active' => true, 'stock' => 5]);
        $rosa = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id, 'stock' => 5]);
        $azul = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id, 'stock' => 5]);
        $outro = Product::factory()->create(['is_active' => true, 'stock' => 5]);
        $semNota = Product::factory()->create(['is_active' => true, 'stock' => 5]);

        $this->avaliar($pai, 5);
        $this->avaliar($rosa, 4);
        $this->avaliar($azul, 3);
        $this->avaliar($outro, 1);

        $cards = $this->cards();

        $this->assertEqualsWithDelta(4.0, (float) $cards[$pai->id]['reviews_avg_rating'], 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $cards[$outro->id]['reviews_avg_rating'], 0.001);
        $this->assertNull($cards[$semNota->id]['reviews_avg_rating']);

        // Quantidade ao lado das estrelas (pedido 2026-10-22), variações incluídas.
        $this->assertSame(3, (int) $cards[$pai->id]['reviews_count']);
        $this->assertSame(1, (int) $cards[$outro->id]['reviews_count']);
        $this->assertSame(0, (int) $cards[$semNota->id]['reviews_count']);
    }

    public function test_variacao_apagada_nao_entra_na_media(): void
    {
        $pai = Product::factory()->create(['is_active' => true, 'stock' => 5]);
        $apagada = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id, 'stock' => 5]);

        $this->avaliar($pai, 5);
        $this->avaliar($apagada, 1);
        $apagada->delete();

        $card = $this->cards()[$pai->id];
        $this->assertEqualsWithDelta(5.0, (float) $card['reviews_avg_rating'], 0.001);
        $this->assertSame(1, (int) $card['reviews_count']);
    }
}
