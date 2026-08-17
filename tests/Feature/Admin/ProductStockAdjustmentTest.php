<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-17 ("atualizando produtos... alterando o número de
 * estoque sem querer"): a tela de edição mandava o estoque como valor
 * ABSOLUTO pré-carregado quando a página abriu. Se uma venda de verdade
 * descontasse o estoque enquanto a tela ficava aberta, salvar sobrescrevia
 * o estoque real pelo valor antigo da tela — achados reais ao vivo:
 * ajustes de -51/-167/-182/-240 unidades numa tacada só, no mesmo dia de
 * vendas reais pro mesmo produto. Fix: o campo agora manda um AJUSTE
 * (stock_adjustment, +/-, default 0) aplicado direto sobre o estoque ATUAL
 * do banco — nunca mais um valor absoluto comparado contra um snapshot
 * antigo.
 */
class ProductStockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function updatePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->price,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * O cenário exato do bug: a página foi carregada com estoque=50, uma
     * venda real consumiu 3 unidades ENQUANTO a tela ficava aberta (agora
     * o banco tem 47), e o admin salva o formulário sem tocar no campo de
     * estoque. O resultado tem que continuar 47 — nunca voltar pra 50.
     */
    public function test_saving_the_edit_form_without_touching_stock_never_reverts_a_concurrent_sale(): void
    {
        $product = Product::factory()->create(['stock' => 50]);

        // Venda real acontecendo "enquanto a tela estava aberta".
        app(StockManager::class)->adjust($product, -3, StockMovement::TYPE_SALE, reason: 'Venda importada');

        $response = $this->actingAs($this->admin())
            ->put("/admin/produtos/{$product->id}", $this->updatePayload($product));

        $response->assertRedirect();
        $this->assertSame(47, $product->fresh()->stock);
    }

    public function test_stock_adjustment_zero_never_creates_a_stock_movement(): void
    {
        $product = Product::factory()->create(['stock' => 20]);

        $this->actingAs($this->admin())
            ->put("/admin/produtos/{$product->id}", $this->updatePayload($product, ['stock_adjustment' => 0]));

        $this->assertSame(20, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_positive_stock_adjustment_adds_to_the_current_stock(): void
    {
        $product = Product::factory()->create(['stock' => 20]);

        $this->actingAs($this->admin())
            ->put("/admin/produtos/{$product->id}", $this->updatePayload($product, ['stock_adjustment' => 5]));

        $this->assertSame(25, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity' => 5,
            'reason' => 'Ajuste manual no cadastro do produto',
        ]);
    }

    public function test_negative_stock_adjustment_subtracts_from_the_current_stock_not_from_a_stale_page_value(): void
    {
        $product = Product::factory()->create(['stock' => 50]);

        // Mesma corrida do 1º teste: venda real primeiro, DEPOIS o admin
        // decide corrigir -3 a mais por conta própria (ex: item avariado
        // achado na prateleira) — o ajuste tem que valer sobre o estoque
        // JÁ DESCONTADO pela venda (47 - 3 = 44), nunca sobre o valor
        // antigo da tela (50 - 3 = 47).
        app(StockManager::class)->adjust($product, -3, StockMovement::TYPE_SALE, reason: 'Venda importada');

        $this->actingAs($this->admin())
            ->put("/admin/produtos/{$product->id}", $this->updatePayload($product, ['stock_adjustment' => -3]));

        $this->assertSame(44, $product->fresh()->stock);
    }
}
