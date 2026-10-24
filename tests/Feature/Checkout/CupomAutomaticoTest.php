<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\DescontoPix;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 5% do Pix viram 3% + 2% (pedido do Lira 2026-10-24): o cupom BEMVINDO (3%,
 * qualquer pagamento) entra sozinho no checkout e o Pix dá mais 2% sobre o
 * valor antes do cupom.
 */
class CupomAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->withoutVite();
        config(['loja.cupom_automatico' => 'BEMVINDO']);
        // A migração já cria o BEMVINDO de 3%.
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
    }

    private function noCarrinho(float $preco = 200, array $extra = []): Product
    {
        $product = Product::factory()->create(array_merge(['price' => $preco, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 2, 'discount_percentage' => null, 'discount_amount' => null], $extra));
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        return $product;
    }

    private function entrega(): array
    {
        return [
            'shipping_method_id' => $this->frete->id,
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(11) 99999-0000', 'zip' => '03187-040',
                'street' => 'Rua Mogi Mirim', 'number' => '20', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ];
    }

    public function test_migracao_cria_o_bemvindo_de_3_por_cento(): void
    {
        $cupom = Coupon::buscar('BEMVINDO');

        $this->assertNotNull($cupom);
        $this->assertTrue($cupom->is_active);
        $this->assertSame(Coupon::TYPE_PERCENTAGE, $cupom->discount_type);
        $this->assertEquals(3, (float) $cupom->discount_value);
        $this->assertNull($cupom->max_uses);
        $this->assertFalse($cupom->one_per_customer);
    }

    public function test_checkout_abre_com_o_cupom_aplicado(): void
    {
        $this->noCarrinho(200);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Checkout/CheckoutV2', false)
            ->where('couponCode', 'BEMVINDO')
            ->where('discountAmount', 6)
            ->where('pixDiscountPercentage', 2));
        $this->assertSame('BEMVINDO', session('checkout_draft.coupon_code'));
    }

    public function test_pix_soma_5_por_cento(): void
    {
        $this->noCarrinho(200);
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 77, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->get('/finalizacao');
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('BEMVINDO', $order->coupon_code);
        $this->assertEquals(4, (float) $order->pix_discount_amount);
        $this->assertEquals(10, (float) $order->discount_amount);
        $this->assertEquals(190, (float) $order->total);
    }

    public function test_cartao_fica_so_com_os_3_por_cento(): void
    {
        $this->noCarrinho(200);

        // Tela v1 (Payment.vue): total do cartão e o desconto que o Pix somaria.
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->withSession(['checkout_version' => 1])->get('/finalizacao/pagamento')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', 'BEMVINDO')
            ->where('discountAmount', 6)
            ->where('total', 194)
            ->where('pixDiscountAmount', 4));
    }

    public function test_cliente_que_tira_o_cupom_nao_ganha_ele_de_novo(): void
    {
        $this->noCarrinho(200);
        $this->get('/finalizacao');

        $this->deleteJson('/finalizacao/cupom')->assertOk();

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', null)
            ->where('discountAmount', 0));
    }

    public function test_outro_cupom_troca_o_automatico(): void
    {
        Coupon::create(['code' => 'DEZOFF', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10, 'is_active' => true]);
        $this->noCarrinho(200);
        $this->get('/finalizacao');

        $this->postJson('/finalizacao/cupom', ['code' => 'DEZOFF'])->assertOk()->assertJson(['discount_amount' => 20]);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('couponCode', 'DEZOFF'));
    }

    public function test_cupom_desligado_nao_entra(): void
    {
        Coupon::buscar('BEMVINDO')->update(['is_active' => false]);
        $this->noCarrinho(200);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('couponCode', null));
    }

    public function test_pix_nao_passa_do_que_sobra_depois_do_cupom(): void
    {
        $this->assertEquals(4.0, DescontoPix::descontoSobreCupom(200, 6, 2));
        $this->assertEquals(2.0, DescontoPix::descontoSobreCupom(200, 198, 2));
    }

    public function test_pagina_de_produto_mostra_5_por_cento_no_pix(): void
    {
        $product = Product::factory()->create(['price' => 200, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 2, 'discount_percentage' => null, 'discount_amount' => null]);

        $this->assertEquals(round((float) $product->final_price * 0.95, 2), DescontoPix::precoPix($product));

        config(['loja.cupom_automatico' => '']);
        \Illuminate\Support\Once::flush();
        $this->assertEquals(round((float) $product->final_price * 0.98, 2), DescontoPix::precoPix($product->fresh()));
    }

    public function test_ferrakora_tambem_ganha_o_cupom_sozinho(): void
    {
        Mail::fake();
        Bus::fake();
        $product = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 2, 'discount_percentage' => null, 'discount_amount' => null]);
        FerraKoraProduto::create(['product_id' => $product->id, 'categoria' => 'Trenas', 'ordem' => 1, 'ativo' => true]);
        $itens = [['variante' => 'kzv-'.$product->id, 'quantidade' => 2]];

        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $itens])
            ->assertOk()
            ->assertJsonPath('cupom.codigo', 'BEMVINDO')
            ->assertJsonPath('desconto_cupom', 600)
            ->assertJsonPath('total', 19400)
            ->assertJsonPath('desconto_pix', 400)
            ->assertJsonPath('total_pix', 19000);

        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $itens, 'sem_cupom_automatico' => true])
            ->assertOk()
            ->assertJsonPath('cupom', null)
            ->assertJsonPath('total', 20000)
            ->assertJsonPath('total_pix', 19600);
    }
}
