<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Clube de Avaliações (aprovado 2026-10-20): cupom pessoal por nível e nome oculto. */
class ClubeDeAvaliacoesTest extends TestCase
{
    use RefreshDatabase;

    private function entregue(User $user): Product
    {
        $product = Product::factory()->create(['is_active' => true]);
        $order = Order::create([
            'user_id' => $user->id, 'status' => Order::STATUS_COMPLETED, 'origin' => Order::ORIGIN_STORE, 'shipping_name' => 'X', 'shipping_phone' => '1', 'shipping_zip' => '1',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'C', 'shipping_state' => 'SP',
            'subtotal' => 10, 'shipping_cost' => 0, 'total' => 10,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $product;
    }

    private function avaliar(User $user, Product $product, string $comentario = 'Chegou certinho e funciona muito bem'): void
    {
        $this->actingAs($user)->post("/produtos/{$product->id}/avaliacoes", ['rating' => 5, 'comment' => $comentario])->assertSessionHasNoErrors();
    }

    public function test_bronze_sai_com_30_pontos_uma_vez_so(): void
    {
        $user = User::factory()->create();

        $this->avaliar($user, $this->entregue($user)); // 20
        // Fora o BEMVINDO, que a migração cria (cupom automático, 2026-10-24).
        $this->assertSame(0, Coupon::where('code', '<>', 'BEMVINDO')->count());

        $this->avaliar($user, $this->entregue($user), 'ok'); // 30
        $cupom = Coupon::where('code', '<>', 'BEMVINDO')->sole();
        $this->assertSame($user->id, $cupom->user_id);
        $this->assertSame(5.0, (float) $cupom->discount_value);
        $this->assertSame(1, $cupom->max_uses);
        $this->assertStringStartsWith('AVALBRONZE-', $cupom->code);

        // Editar a mesma avaliação não gera outro cupom.
        $this->avaliar($user, Product::find(Order::latest('id')->first()->items->first()->product_id), 'ok de novo');
        $this->assertSame(1, Coupon::where('code', '<>', 'BEMVINDO')->count());

        $this->actingAs($user)->get('/avaliar')->assertInertia(fn ($page) => $page
            ->where('clube.pontos', 30)
            ->where('clube.niveis.0.cupom.codigo', $cupom->code)
            ->where('clube.niveis.1.cupom', null));
    }

    public function test_cupom_pessoal_so_vale_para_a_conta_dona(): void
    {
        $dono = User::factory()->create();
        $outro = User::factory()->create();
        $cupom = Coupon::create(['code' => 'AVALBRONZE-TESTE1', 'user_id' => $dono->id, 'name' => 'x', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 5, 'is_active' => true]);

        $this->assertNull($cupom->motivoInvalido(100, $dono));
        $this->assertNotNull($cupom->motivoInvalido(100, $outro));
        $this->assertNotNull($cupom->motivoInvalido(100, null, 'visitante@x.com'));
    }

    public function test_nome_oculto_nao_vai_para_a_pagina_do_produto(): void
    {
        $user = User::factory()->create(['name' => 'Maria Secreta']);
        $product = $this->entregue($user);

        $this->actingAs($user)->post("/produtos/{$product->id}/avaliacoes", ['rating' => 4, 'comment' => 'Bom', 'ocultar_nome' => true]);
        $this->assertTrue(Review::sole()->ocultar_nome);

        auth()->logout();
        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reviews.0.reviewer_display_name', 'Cliente KazaKora'))
            ->assertDontSee('Maria Secreta');
    }
}
