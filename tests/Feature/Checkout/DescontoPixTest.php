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
 * Desconto de 5% no Pix da loja (pedido 2026-10-09): o preço da loja leva
 * +5% embutido (cadastro, importação de marketplace e atualização em massa),
 * o Pix devolve o desconto e os marketplaces recebem o preço sem o +5%.
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

    public function test_helper_soma_e_tira_o_acrescimo_sem_perder_centavos(): void
    {
        $this->assertSame(105.0, DescontoPix::comAcrescimo(100.0));
        $this->assertSame(100.0, DescontoPix::semAcrescimo(105.0));
        $this->assertSame(99.99, DescontoPix::semAcrescimo(DescontoPix::comAcrescimo(99.99)));
        $this->assertSame(5.25, DescontoPix::desconto(105.0));
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

    public function test_tela_de_pagamento_recebe_o_desconto_do_pix(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 105, 'stock' => 5, 'is_active' => true]);
        $this->chegarNoPagamento($user, $product);

        $this->actingAs($user)->get('/finalizacao/pagamento')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pixDiscountPercentage', 5)
                ->where('pixDiscountAmount', 5.25));
    }

    public function test_cadastro_no_admin_grava_o_preco_com_mais_5_por_cento(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/produtos', [
            'name' => 'Cesto de Bambu', 'category_id' => null, 'price' => 100, 'stock' => 3, 'discount_amount' => 10,
            'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
        ])->assertRedirect();

        $product = Product::where('name', 'Cesto de Bambu')->firstOrFail();
        $this->assertEquals(105, (float) $product->price);
        $this->assertEquals(10.5, (float) $product->discount_amount);

        // A edição mostra o valor do Pix (sem o +5%) e salvar de novo não acumula.
        $this->actingAs($admin)->get("/admin/produtos/{$product->id}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('precoPix.price', 100)->etc());

        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", [
            'name' => 'Cesto de Bambu', 'category_id' => null, 'sku' => $product->sku, 'price' => 100, 'discount_amount' => 10,
            'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
        ])->assertRedirect();

        $this->assertEquals(105, (float) $product->fresh()->price);
    }

    public function test_marketplace_sem_preco_proprio_recebe_o_preco_sem_o_acrescimo(): void
    {
        $product = Product::factory()->create(['price' => 105]);
        $semPreco = new ProductChannelListing(['price' => null]);
        $comPreco = new ProductChannelListing(['price' => 120]);

        $this->assertSame(100.0, $semPreco->precoDeVenda($product));
        $this->assertSame(120.0, $comPreco->precoDeVenda($product));
    }

    public function test_comando_sobe_os_precos_uma_vez_com_backup_e_desfaz(): void
    {
        Storage::fake('local');
        $a = Product::factory()->create(['price' => 100, 'discount_amount' => null]);
        $b = Product::factory()->create(['price' => 39.93, 'discount_amount' => 7, 'is_active' => false]);

        $this->artisan('produtos:acrescimo-pix')->assertSuccessful();
        $this->assertEquals(100, (float) $a->fresh()->price);

        $this->artisan('produtos:acrescimo-pix --executar')->assertSuccessful();
        $this->assertEquals(105, (float) $a->fresh()->price);
        $this->assertEquals(41.93, (float) $b->fresh()->price);
        $this->assertEquals(7.35, (float) $b->fresh()->discount_amount);
        $this->assertNotNull(Setting::get('loja.acrescimo_pix_aplicado_em'));

        // Não roda duas vezes.
        $this->artisan('produtos:acrescimo-pix --executar')->assertFailed();
        $this->assertEquals(105, (float) $a->fresh()->price);

        $backup = collect(Storage::disk('local')->files('backups'))->first();
        $this->artisan("produtos:acrescimo-pix --restaurar={$backup}")->assertSuccessful();
        $this->assertEquals(100, (float) $a->fresh()->price);
        $this->assertEquals(7, (float) $b->fresh()->discount_amount);
    }
}
