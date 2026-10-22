<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modelos ligados (2026-10-21): anúncios separados (Caixa Sanfonada 85 e 168
 * peças) com o mesmo grupo mostram um seletor "Modelo" na página, cada opção
 * com a foto principal do anúncio e o link para a página dele.
 */
class ModelosLigadosTest extends TestCase
{
    use RefreshDatabase;

    private function foto(Product $product, string $path, int $position, bool $primary = false): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            'path' => $path,
            'thumb_path' => $path,
            'position' => $position,
            'is_primary' => $primary,
        ]);
    }

    public function test_pagina_envia_os_modelos_do_grupo_com_foto_principal_e_link(): void
    {
        $menor = Product::factory()->create(['is_active' => true, 'grupo_modelos' => 'caixa-sanfonada', 'rotulo_modelo' => '85 peças']);
        $maior = Product::factory()->create(['is_active' => true, 'grupo_modelos' => 'caixa-sanfonada', 'rotulo_modelo' => '168 peças']);
        $this->foto($menor, 'products/menor/detalhe.jpg', 0);
        $principalMenor = $this->foto($menor, 'products/menor/principal.jpg', 1, primary: true);
        $primeiraMaior = $this->foto($maior, 'products/maior/primeira.jpg', 0);
        $this->foto($maior, 'products/maior/segunda.jpg', 1);

        $this->get("/produtos/{$menor->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('modelos', 2)
                // Ordem natural pelo rótulo: 85 antes de 168.
                ->where('modelos.0.id', $menor->id)
                ->where('modelos.0.rotulo', '85 peças')
                ->where('modelos.0.atual', true)
                ->where('modelos.0.thumb_url', $principalMenor->thumb_url)
                ->where('modelos.0.foto_url', $principalMenor->media_url)
                ->where('modelos.1.id', $maior->id)
                ->where('modelos.1.rotulo', '168 peças')
                ->where('modelos.1.atual', false)
                ->where('modelos.1.url', "/produtos/{$maior->slug}")
                // Sem foto principal marcada: a primeira por posição.
                ->where('modelos.1.foto_url', $primeiraMaior->media_url));

        // Continuam anúncios separados: nada de variações.
        $this->get("/produtos/{$maior->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('variations', 0)
                ->where('modelos.1.atual', true));
    }

    public function test_grupo_com_um_so_produto_nao_mostra_seletor(): void
    {
        $sozinho = Product::factory()->create(['is_active' => true, 'grupo_modelos' => 'caixa-sanfonada', 'rotulo_modelo' => '85 peças']);
        Product::factory()->create(['is_active' => true, 'grupo_modelos' => 'outro-grupo']);

        $this->get("/produtos/{$sozinho->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('modelos', 0));
    }

    public function test_produto_inativo_nao_entra_no_grupo(): void
    {
        $a = Product::factory()->create(['is_active' => true, 'grupo_modelos' => 'caixa-sanfonada']);
        $b = Product::factory()->create(['is_active' => true, 'grupo_modelos' => 'caixa-sanfonada']);
        Product::factory()->create(['is_active' => false, 'grupo_modelos' => 'caixa-sanfonada']);

        $this->get("/produtos/{$a->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('modelos', 2)
                ->where('modelos', fn ($modelos) => collect($modelos)->pluck('id')->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all()));
    }

    public function test_produto_sem_grupo_nao_tem_modelos(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('modelos', 0));
    }

    public function test_admin_salva_grupo_e_nome_do_modelo(): void
    {
        $product = Product::factory()->create(['price' => 100]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->put("/admin/produtos/{$product->id}", [
                'name' => $product->name,
                'price' => 100,
                'is_active' => true,
                'grupo_modelos' => ' Caixa Sanfonada ',
                'rotulo_modelo' => '85 peças',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('caixa-sanfonada', $product->grupo_modelos);
        $this->assertSame('85 peças', $product->rotulo_modelo);

        // Limpar o campo tira o produto do grupo.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->put("/admin/produtos/{$product->id}", [
                'name' => $product->name,
                'price' => 100,
                'is_active' => true,
                'grupo_modelos' => '',
                'rotulo_modelo' => '',
            ])
            ->assertRedirect();

        $product->refresh();
        $this->assertNull($product->grupo_modelos);
        $this->assertNull($product->rotulo_modelo);
    }
}
