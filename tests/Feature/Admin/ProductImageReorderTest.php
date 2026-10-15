<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arrastar e soltar da galeria no admin (2026-10-15): a ordem enviada vira
 * `position` e a primeira foto passa a ser a principal.
 */
class ProductImageReorderTest extends TestCase
{
    use RefreshDatabase;

    private function produtoComFotos(): array
    {
        $product = Product::factory()->create();
        $fotos = collect(range(0, 2))->map(fn ($posicao) => $product->images()->create([
            'path' => "products/{$product->id}/foto-{$posicao}.jpg",
            'thumb_path' => "products/{$product->id}/foto-{$posicao}-thumb.jpg",
            'position' => $posicao,
            'is_primary' => $posicao === 0,
        ]));

        return [$product, $fotos];
    }

    public function test_salva_a_nova_ordem_e_a_primeira_vira_principal(): void
    {
        [$product, $fotos] = $this->produtoComFotos();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $novaOrdem = [$fotos[2]->id, $fotos[0]->id, $fotos[1]->id];

        $this->actingAs($admin)
            ->put("/admin/produtos/{$product->id}/imagens/ordem", ['ids' => $novaOrdem])
            ->assertRedirect();

        $this->assertSame($novaOrdem, $product->images()->pluck('id')->all());
        $this->assertSame([true, false, false], $product->images()->pluck('is_primary')->all());
    }

    public function test_recusa_lista_com_foto_de_outro_produto_ou_faltando(): void
    {
        [$product, $fotos] = $this->produtoComFotos();
        [, $outras] = $this->produtoComFotos();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->put("/admin/produtos/{$product->id}/imagens/ordem", ['ids' => [$fotos[1]->id, $fotos[0]->id, $outras[0]->id]])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->put("/admin/produtos/{$product->id}/imagens/ordem", ['ids' => [$fotos[1]->id, $fotos[0]->id]])
            ->assertStatus(422);

        $this->assertSame([$fotos[0]->id, $fotos[1]->id, $fotos[2]->id], $product->images()->pluck('id')->all());
        $this->assertSame([$outras[0]->id], [$outras[0]->fresh()->id]);
        $this->assertSame(0, $outras[0]->fresh()->position);
    }
}
