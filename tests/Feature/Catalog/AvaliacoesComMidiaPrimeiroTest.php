<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Avaliações com foto primeiro, depois as só com texto (pedido 2026-10-10). */
class AvaliacoesComMidiaPrimeiroTest extends TestCase
{
    use RefreshDatabase;

    public function test_avaliacoes_com_midia_vem_antes(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $semFoto = Review::create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'rating' => 5, 'comment' => 'Só texto']);
        $comFoto = Review::create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'rating' => 4, 'comment' => 'Com foto']);
        $comFoto->images()->create(['image_url' => 'https://exemplo.com/foto.jpg', 'position' => 0]);
        $semFoto->forceFill(['created_at' => now()->addMinute()])->save();

        $this->get('/produtos/'.$product->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reviews.0.id', $comFoto->id)
            ->where('reviews.1.id', $semFoto->id));
    }
}
