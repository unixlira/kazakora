<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foto da variação escolhida no admin (2026-10-20): a aba Variações marca
 * qual foto do produto a loja mostra quando o cliente escolhe a variação.
 */
class ProductImageVariacaoTest extends TestCase
{
    use RefreshDatabase;

    private function produtoComFotos(array $atributos = []): array
    {
        $product = Product::factory()->create(['is_active' => true] + $atributos);
        $fotos = collect(range(0, 2))->map(fn ($posicao) => $product->images()->create([
            'path' => "products/{$product->id}/foto-{$posicao}.jpg",
            'thumb_path' => "products/{$product->id}/foto-{$posicao}-thumb.jpg",
            'position' => $posicao,
            'is_primary' => $posicao === 0,
        ]));

        return [$product, $fotos];
    }

    public function test_escolhe_troca_e_volta_para_automatica(): void
    {
        [$product, $fotos] = $this->produtoComFotos();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->put("/admin/produtos/{$product->id}/imagens/variacao", ['image_id' => $fotos[2]->id])
            ->assertRedirect();
        $this->assertSame([false, false, true], $product->images()->pluck('is_variant')->all());

        $this->actingAs($admin)
            ->put("/admin/produtos/{$product->id}/imagens/variacao", ['image_id' => $fotos[1]->id])
            ->assertRedirect();
        $this->assertSame([false, true, false], $product->images()->pluck('is_variant')->all());

        $this->actingAs($admin)
            ->put("/admin/produtos/{$product->id}/imagens/variacao", ['image_id' => null])
            ->assertRedirect();
        $this->assertSame([false, false, false], $product->images()->pluck('is_variant')->all());
        // A principal não muda.
        $this->assertSame([true, false, false], $product->images()->pluck('is_primary')->all());
    }

    public function test_recusa_foto_de_outro_produto(): void
    {
        [$product] = $this->produtoComFotos();
        [, $outras] = $this->produtoComFotos();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->put("/admin/produtos/{$product->id}/imagens/variacao", ['image_id' => $outras[1]->id])
            ->assertStatus(422);

        $this->assertFalse($outras[1]->fresh()->is_variant);
    }

    public function test_loja_mostra_a_foto_escolhida_ao_escolher_a_variacao(): void
    {
        [$pai] = $this->produtoComFotos();
        [$filho, $fotosFilho] = $this->produtoComFotos(['parent_product_id' => $pai->id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->put("/admin/produtos/{$filho->id}/imagens/variacao", ['image_id' => $fotosFilho[2]->id]);

        $this->get("/produtos/{$pai->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('variations.0.images.0.url', $fotosFilho[2]->url)
                ->where('galeria.3.url', $fotosFilho[2]->url)
                ->where('galeria.3.variant_id', $filho->id));
    }

    public function test_aba_variacoes_recebe_fotos_do_pai_e_das_irmas(): void
    {
        [$pai] = $this->produtoComFotos();
        [$filho] = $this->produtoComFotos(['parent_product_id' => $pai->id]);
        [$irma] = $this->produtoComFotos(['parent_product_id' => $pai->id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get("/admin/produtos/{$filho->id}/editar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('variations.parent.images', 3)
                ->has('variations.parent.images.0.is_variant')
                ->where('variations.siblings.0.id', $irma->id)
                ->has('variations.siblings.0.images', 3)
                ->has('images', 3));
    }
}
