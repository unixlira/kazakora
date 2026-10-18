<?php

namespace Tests\Feature\Checkout;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\DescontoPix;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Desconto de 5% no Pix da loja (pedido 2026-10-09), só no checkout. Desde
 * 2026-10-18 o preço da loja é o valor digitado no cadastro, sem +5%, e os
 * marketplaces sem preço próprio recebem esse mesmo valor.
 */
class DescontoPixTest extends TestCase
{
    use RefreshDatabase;

    private function chegarNoPagamento(User $user, Product $product): void
    {
        $metodo = ShippingMethod::factory()->create(['price' => 10, 'is_active' => true]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($user)->post('/finalizacao/entrega', [
            'shipping_method_id' => $metodo->id,
            'new_address' => [
                'recipient_name' => 'Cliente', 'phone' => '11999999999', 'zip' => '01000-000',
                'street' => 'Rua A', 'number' => '1', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ])->assertRedirect(route('finalizacao.pagamento'));
    }

    public function test_helper_calcula_o_desconto_do_pix(): void
    {
        $this->assertSame(5.25, DescontoPix::desconto(105.0, 5));
        $this->assertSame(0.0, DescontoPix::desconto(105.0, 0));
    }

    public function test_pagando_no_pix_o_pedido_sai_com_5_por_cento_de_desconto(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 105, 'stock' => 5, 'is_active' => true]);
        $this->chegarNoPagamento($user, $product);

        $cobrado = null;
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use (&$cobrado) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturnUsing(function ($amount) use (&$cobrado) {
                $cobrado = $amount;

                return ['id' => 123, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'x']]];
            });
        });

        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'terms_accepted' => true]);

        $order = Order::firstOrFail();
        $this->assertEquals(5.25, $order->pix_discount_amount);
        $this->assertEquals(5.25, $order->discount_amount);
        $this->assertEquals(round(105 - 5.25 + (float) $order->shipping_cost, 2), (float) $order->total);
        $this->assertEquals((float) $order->total, $cobrado);
    }

    public function test_desconto_checkout_e_o_do_produto_e_vazio_nao_da_desconto(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $dez = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 10]);
        $sem = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => null]);
        $this->chegarNoPagamento($user, $dez);
        $this->actingAs($user)->post('/carrinho', ['product_id' => $sem->id, 'quantity' => 1]);

        // Média pesada pelo subtotal: (100 x 10% + 100 x 0%) / 200 = 5%.
        $this->actingAs($user)->get('/finalizacao/pagamento?v=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pixDiscountPercentage', 5)
                ->where('pixDiscountAmount', 10));

        $this->assertSame(0.0, DescontoPix::percentualDo($sem));
        $this->assertSame(10.0, DescontoPix::percentualDo($dez));
    }

    public function test_desconto_checkout_em_valor_fixo_sai_no_centavo(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $fixo = Product::factory()->create(['price' => 89.90, 'stock' => 5, 'is_active' => true,
            'checkout_discount_type' => DescontoPix::FIXO, 'checkout_discount_amount' => 10, 'checkout_discount_percentage' => null]);
        $cinco = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $this->chegarNoPagamento($user, $fixo);
        $this->actingAs($user)->post('/carrinho', ['product_id' => $cinco->id, 'quantity' => 1]);

        // R$ 10 do fixo + 5% de R$ 100 = R$ 15.
        $this->actingAs($user)->get('/finalizacao/pagamento?v=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pixDiscountAmount', 15));

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()
                ->andReturn(['id' => 123, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'x']]]);
        });
        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'terms_accepted' => true]);

        $this->assertEquals(15.0, (float) Order::firstOrFail()->pix_discount_amount);
        $this->assertSame(20.0, DescontoPix::descontoDo($fixo, 89.90, 2));
        $this->assertSame(11.12, DescontoPix::percentualDo($fixo, 89.90));
    }

    public function test_tela_de_pagamento_recebe_o_desconto_do_pix(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 105, 'stock' => 5, 'is_active' => true]);
        $this->chegarNoPagamento($user, $product);

        $this->actingAs($user)->get('/finalizacao/pagamento?v=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pixDiscountPercentage', 5)
                ->where('pixDiscountAmount', 5.25));
    }

    public function test_cadastro_no_admin_grava_o_preco_digitado(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/produtos', [
            'name' => 'Cesto de Bambu', 'category_id' => null, 'price' => 100, 'stock' => 3,
            'discount_percentage' => 10, 'checkout_discount_percentage' => 7, 'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
        ])->assertRedirect();

        $product = Product::where('name', 'Cesto de Bambu')->firstOrFail();
        $this->assertEquals(100, (float) $product->price);
        $this->assertEquals(10, (float) $product->discount_percentage);
        $this->assertEquals(7, (float) $product->checkout_discount_percentage);
        $this->assertNull($product->discount_amount);
        $this->assertEquals(90.0, $product->precoSemOferta());

        // A edição mostra o preço gravado e salvar de novo não muda nada.
        $this->actingAs($admin)->get("/admin/produtos/{$product->id}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.price', '100.00')->missing('precoPix')->etc());

        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", [
            'name' => 'Cesto de Bambu', 'category_id' => null, 'sku' => $product->sku, 'price' => 100, 'discount_percentage' => 10,
            'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
        ])->assertRedirect();

        $this->assertEquals(100, (float) $product->fresh()->price);
        $this->assertEquals(7, (float) $product->fresh()->checkout_discount_percentage);

        // Desconto Checkout em Valor fixo: grava o R$ e limpa o %.
        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", [
            'name' => 'Cesto de Bambu', 'category_id' => null, 'sku' => $product->sku, 'price' => 100,
            'checkout_discount_type' => DescontoPix::FIXO, 'checkout_discount_amount' => 12.5, 'checkout_discount_percentage' => 7,
            'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(DescontoPix::FIXO, $product->checkout_discount_type);
        $this->assertEquals(12.5, (float) $product->checkout_discount_amount);
        $this->assertNull($product->checkout_discount_percentage);

        // Valor fixo maior que o preço é recusado.
        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", [
            'name' => 'Cesto de Bambu', 'category_id' => null, 'sku' => $product->sku, 'price' => 100,
            'checkout_discount_type' => DescontoPix::FIXO, 'checkout_discount_amount' => 150,
            'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
        ])->assertSessionHasErrors('checkout_discount_amount');
    }

    public function test_marketplace_sem_preco_proprio_recebe_o_preco_da_loja(): void
    {
        $product = Product::factory()->create(['price' => 105]);
        $semPreco = new ProductChannelListing(['price' => null]);
        $comPreco = new ProductChannelListing(['price' => 120]);

        $this->assertSame(105.0, $semPreco->precoDeVenda($product));
        $this->assertSame(120.0, $comPreco->precoDeVenda($product));
    }

    public function test_comando_tira_o_acrescimo_so_dos_ids_com_backup_e_desfaz(): void
    {
        Storage::fake('local');
        $a = Product::factory()->create(['price' => 105, 'discount_amount' => null]);
        $b = Product::factory()->create(['price' => 41.93, 'discount_amount' => 7.35, 'is_active' => false]);
        $direto = Product::factory()->create(['price' => 57.90, 'discount_amount' => null]);

        $this->artisan('produtos:remover-acrescimo-pix')->assertFailed();
        $this->artisan("produtos:remover-acrescimo-pix --ids={$a->id},{$b->id}")->assertSuccessful();
        $this->assertEquals(105, (float) $a->fresh()->price);

        $this->artisan("produtos:remover-acrescimo-pix --ids={$a->id},{$b->id} --executar")->assertSuccessful();
        $this->assertEquals(100, (float) $a->fresh()->price);
        $this->assertEquals(39.93, (float) $b->fresh()->price);
        $this->assertEquals(7, (float) $b->fresh()->discount_amount);
        $this->assertEquals(57.90, (float) $direto->fresh()->price);
        $this->assertNotNull(Setting::get('loja.acrescimo_pix_removido_em'));

        // Não roda duas vezes.
        $this->artisan("produtos:remover-acrescimo-pix --ids={$a->id} --executar")->assertFailed();
        $this->assertEquals(100, (float) $a->fresh()->price);

        $backup = collect(Storage::disk('local')->files('backups'))->first();
        $this->artisan("produtos:remover-acrescimo-pix --restaurar={$backup}")->assertSuccessful();
        $this->assertEquals(105, (float) $a->fresh()->price);
        $this->assertEquals(7.35, (float) $b->fresh()->discount_amount);
    }
}
