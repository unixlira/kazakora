<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Models\User;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Ofertas do dia (pedido 2026-10-10): +5 pontos de desconto em produtos com
 * mais de 25%, nunca com prejuízo, só na loja (não nos marketplaces) e sem
 * acumular com cupom.
 */
class OfertasDoDiaTest extends TestCase
{
    use RefreshDatabase;

    private function produto(array $extra = []): Product
    {
        return Product::factory()->create(array_merge([
            'price' => 200, 'discount_percentage' => 30, 'cost_price' => 40, 'stock' => 10, 'is_active' => true,
        ], $extra));
    }

    public function test_escolhe_so_quem_tem_mais_de_25_e_nao_da_prejuizo(): void
    {
        config(['ofertas.completar_com_margem' => false]);
        $boa = $this->produto();
        $prejuizo = $this->produto(['cost_price' => 110]);
        $poucoDesconto = $this->produto(['discount_percentage' => 10]);
        $semCusto = $this->produto(['cost_price' => null]);
        $semEstoque = $this->produto(['stock' => 0]);

        $this->artisan('loja:ofertas-do-dia')->assertSuccessful();

        $this->assertSame([$boa->id], OfertaDoDia::query()->pluck('product_id')->all());
        $oferta = OfertaDoDia::query()->first();
        $this->assertSame(130.0, $oferta->preco_oferta); // 140 − 5% de 200
        $this->assertGreaterThan(0, $oferta->lucro_estimado);

        // Loja vê o preço da oferta; marketplace continua no preço sem oferta.
        $this->assertSame(130.0, $boa->fresh()->final_price);
        $this->assertTrue($boa->fresh()->oferta_do_dia);
        $listing = new ProductChannelListing(['product_id' => $boa->id, 'channel' => 'mercado_livre']);
        $this->assertSame(round(140 / 1.05, 2), round($listing->precoDeVenda($boa->fresh()), 2));
        $this->assertSame(140.0, $prejuizo->fresh()->final_price);
    }

    public function test_nao_repete_quem_foi_oferta_ontem_quando_ha_outros(): void
    {
        config(['ofertas.quantidade' => 1, 'ofertas.completar_com_margem' => false]);
        $ontem = $this->produto();
        $outro = $this->produto();
        OfertaDoDia::query()->create(['data' => now()->subDay()->toDateString(), 'product_id' => $ontem->id, 'desconto_extra' => 5, 'preco_antes' => 140, 'preco_oferta' => 130, 'lucro_estimado' => 50]);

        $this->artisan('loja:ofertas-do-dia')->assertSuccessful();

        $this->assertSame([$outro->id], OfertaDoDia::query()->whereDate('data', now()->toDateString())->pluck('product_id')->all());
    }

    public function test_completa_com_produtos_de_margem_quando_faltam(): void
    {
        $semDesconto = $this->produto(['discount_percentage' => null, 'price' => 100, 'cost_price' => 20]);

        $this->artisan('loja:ofertas-do-dia')->assertSuccessful();

        $this->assertSame(95.0, OfertaDoDia::query()->where('product_id', $semDesconto->id)->value('preco_oferta'));
    }

    public function test_cupom_nao_desconta_produto_em_oferta_e_home_nao_mostra_na_secao(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        config(['ofertas.completar_com_margem' => false]);
        $emOferta = $this->produto();
        $normal = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $this->artisan('loja:ofertas-do-dia');
        Coupon::create(['code' => 'DEZ', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);

        $this->post('/carrinho', ['product_id' => $emOferta->id, 'quantity' => 1]);
        $this->postJson('/finalizacao/cupom', ['code' => 'dez'])->assertStatus(422);

        $this->post('/carrinho', ['product_id' => $normal->id, 'quantity' => 1]);
        $this->postJson('/finalizacao/cupom', ['code' => 'dez'])->assertOk()->assertJson(['discount_amount' => 10]);

        // Desde 2026-10-18 a seção da home é só de Oferta Relâmpago.
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('vitrine.ofertas', [])
            ->where('products', null));
    }

    public function test_produtos_fixos_vem_primeiro_na_ordem_e_respeitam_estoque_e_margem(): void
    {
        config(['ofertas.completar_com_margem' => false]);
        $outro = $this->produto();
        $semDesconto = $this->produto(['discount_percentage' => null, 'price' => 100, 'cost_price' => 20]);
        $primeiro = $this->produto();
        $semEstoque = $this->produto(['stock' => 0]);
        $prejuizo = $this->produto(['cost_price' => 110]);
        config(['ofertas.fixas' => [$semDesconto->id, $semEstoque->id, $prejuizo->id, $primeiro->id]]);
        Product::query()->whereKey(config('ofertas.fixas'))->update(['is_daily_offer' => true]);
        OfertaDoDia::query()->create(['data' => now()->subDay()->toDateString(), 'product_id' => $primeiro->id, 'desconto_extra' => 5, 'preco_antes' => 140, 'preco_oferta' => 130, 'lucro_estimado' => 50]);

        $this->artisan('loja:ofertas-do-dia')->assertSuccessful();

        $hoje = OfertaDoDia::query()->whereDate('data', now()->toDateString())->orderBy('posicao');
        $this->assertSame([$semDesconto->id, $primeiro->id, $outro->id], $hoje->pluck('product_id')->all());
        $this->assertSame(95.0, OfertaDoDia::query()->where('product_id', $semDesconto->id)->value('preco_oferta'));
    }

    public function test_marcados_no_admin_substituem_o_config_como_fixos(): void
    {
        config(['ofertas.completar_com_margem' => false, 'ofertas.quantidade' => 2]);
        $doConfig = $this->produto();
        $marcado = $this->produto(['discount_percentage' => null, 'price' => 100, 'cost_price' => 20, 'is_daily_offer' => true]);
        config(['ofertas.fixas' => [$doConfig->id]]);

        $this->artisan('loja:ofertas-do-dia')->assertSuccessful();

        $hoje = OfertaDoDia::query()->whereDate('data', now()->toDateString())->orderBy('posicao')->pluck('product_id')->all();
        $this->assertSame($marcado->id, $hoje[0]);
    }

    public function test_sem_marcados_o_config_nao_volta_como_fixo(): void
    {
        config(['ofertas.completar_com_margem' => false, 'ofertas.quantidade' => 1]);
        $semDesconto = $this->produto(['discount_percentage' => null, 'price' => 100, 'cost_price' => 20]);
        $comDesconto = $this->produto();
        config(['ofertas.fixas' => [$semDesconto->id]]);

        $this->artisan('loja:ofertas-do-dia')->assertSuccessful();

        $this->assertSame([$comDesconto->id], OfertaDoDia::query()->whereDate('data', now()->toDateString())->pluck('product_id')->all());
    }

    public function test_marcar_no_cadastro_poe_o_produto_na_secao_da_home(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $produto = $this->produto(['discount_percentage' => null, 'price' => 100, 'cost_price' => 20]);
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('vitrine.ofertas', []));

        $this->actingAs($admin)->put("/admin/produtos/{$produto->id}", [
            'name' => $produto->name, 'price' => 100, 'cost_price' => 20, 'is_active' => true, 'is_daily_offer' => true,
        ])->assertRedirect()->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Já aparece nas Ofertas do Dia'));

        $this->assertTrue($produto->fresh()->is_daily_offer);
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('vitrine.ofertas.0.id', $produto->id));
        $this->assertFalse(OfertaDoDia::query()->exists(), 'marcar não pode criar desconto escondido em outros produtos');

        $this->actingAs($admin)->put("/admin/produtos/{$produto->id}", [
            'name' => $produto->name, 'price' => 100, 'cost_price' => 20, 'is_active' => true, 'is_daily_offer' => false,
        ])->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Saiu das Ofertas do Dia'));
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('vitrine.ofertas', []));
    }
}
