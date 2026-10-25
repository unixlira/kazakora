<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\CupomUso;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
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
 * qualquer pagamento) é oferecido no pop-up do checkout (não entra mais
 * sozinho) e o Pix dá mais 2% sobre o valor antes do cupom.
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
        // Uso único por CPF/CNPJ desde 2026-10-25 (migração cupom_usos).
        $this->assertTrue($cupom->one_per_customer);
    }

    private function pedido(array $extra = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_AWAITING_PAYMENT, 'origin' => Order::ORIGIN_STORE, 'coupon_code' => 'BEMVINDO',
            'shipping_name' => 'X', 'shipping_phone' => '11999990000', 'shipping_zip' => '01000000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 97,
        ], $extra));
    }

    public function test_bemvindo_so_fica_usado_quando_o_pagamento_confirma(): void
    {
        Bus::fake();
        $cupom = Coupon::buscar('BEMVINDO');
        $ana = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'ana@exemplo.com', 'cpf' => '123.456.789-09']);
        $order = $this->pedido(['user_id' => $ana->id]);
        $order->payments()->create(['mercadopago_payment_id' => '123', 'provider' => Payment::PROVIDER_MERCADOPAGO, 'method_type' => Payment::METHOD_PIX, 'amount' => 97, 'status' => Payment::STATUS_REQUIRES_CONFIRMATION]);

        // Pedido aberto (Pix ainda não pago) não tira o cupom de ninguém.
        $this->assertNull($cupom->motivoInvalido(100, $ana));
        $this->assertSame(0, CupomUso::count());

        $order->payments()->update(['status' => Payment::STATUS_CAPTURED]);
        $this->assertTrue(app(OrderPaymentFinalizer::class)->finalize($order->fresh()));

        $uso = CupomUso::sole();
        $this->assertSame([$cupom->id, '12345678909', $order->id], [$uso->coupon_id, $uso->documento, $uso->order_id]);
        // Mesmo CPF com outra conta e outro e-mail: barrado.
        $this->assertSame(Coupon::MSG_JA_USOU, $cupom->motivoInvalido(100, null, 'nova@exemplo.com', '12345678909'));
        $this->assertNull($cupom->motivoInvalido(100, null, 'nova@exemplo.com', '98765432100'));

        // Webhook repetido não duplica.
        app(OrderPaymentFinalizer::class)->finalize($order->fresh());
        $this->assertSame(1, CupomUso::count());
    }

    public function test_bemvindo_registra_cnpj_do_comprador(): void
    {
        Bus::fake();
        $order = $this->pedido(['buyer_document' => '12.345.678/0001-95']);
        $order->payments()->create(['mercadopago_payment_id' => '124', 'provider' => Payment::PROVIDER_MERCADOPAGO, 'method_type' => Payment::METHOD_PIX, 'amount' => 97, 'status' => Payment::STATUS_CAPTURED]);

        app(OrderPaymentFinalizer::class)->finalize($order->fresh());

        $this->assertSame('12345678000195', CupomUso::sole()->documento);
        $this->assertSame(Coupon::MSG_JA_USOU, Coupon::buscar('BEMVINDO')->motivoInvalido(100, null, null, '12345678000195'));
    }

    public function test_popup_do_bemvindo_vale_para_quem_ja_comprou_sem_ele(): void
    {
        $ana = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => '12345678909']);
        $this->pedido(['user_id' => $ana->id, 'status' => Order::STATUS_PAID, 'coupon_code' => null]);
        $this->actingAs($ana);
        $this->noCarrinho(200);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('cupomPrimeiraCompra.code', 'BEMVINDO'));

        CupomUso::create(['coupon_id' => Coupon::buscar('BEMVINDO')->id, 'documento' => '12345678909']);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('cupomPrimeiraCompra', null));
        $this->postJson('/finalizacao/cupom', ['code' => 'BEMVINDO'])->assertStatus(422)->assertJsonFragment(['message' => Coupon::MSG_JA_USOU]);
    }

    public function test_checkout_abre_sem_cupom_e_oferece_o_bemvindo_no_popup(): void
    {
        $this->noCarrinho(200);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Checkout/CheckoutV2', false)
            ->where('couponCode', null)
            ->where('discountAmount', 0)
            ->missing('cupomAutomatico')
            ->where('cupomPrimeiraCompra.code', 'BEMVINDO')
            ->where('cupomPrimeiraCompra.descricao', '3% de desconto')
            ->where('pixDiscountPercentage', 2));
        $this->assertNull(session('checkout_draft.coupon_code'));
    }

    public function test_aceitou_o_cupom_e_pagou_no_pix_soma_5_por_cento(): void
    {
        $this->noCarrinho(200);
        $this->mockPix();

        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'BEMVINDO'])->assertOk()->assertJson(['code' => 'BEMVINDO', 'discount_amount' => 6]);
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('BEMVINDO', $order->coupon_code);
        $this->assertEquals(4, (float) $order->pix_discount_amount);
        $this->assertEquals(10, (float) $order->discount_amount);
        $this->assertEquals(190, (float) $order->total);
    }

    public function test_fechou_o_popup_e_pagou_no_pix_fica_so_com_os_2_por_cento(): void
    {
        $this->noCarrinho(200);
        $this->mockPix();

        $this->get('/finalizacao');
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::firstOrFail();
        $this->assertNull($order->coupon_code);
        $this->assertEquals(4, (float) $order->pix_discount_amount);
        $this->assertEquals(4, (float) $order->discount_amount);
        $this->assertEquals(196, (float) $order->total);
    }

    public function test_cartao_com_o_cupom_fica_com_os_3_por_cento(): void
    {
        $this->noCarrinho(200);

        // Tela v1 (Payment.vue): total do cartão e o desconto que o Pix somaria.
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'BEMVINDO'])->assertOk();
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->withSession(['checkout_version' => 1])->get('/finalizacao/pagamento')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', 'BEMVINDO')
            ->where('discountAmount', 6)
            ->where('total', 194)
            ->where('pixDiscountAmount', 4));
    }

    public function test_cartao_sem_o_cupom_nao_tem_desconto(): void
    {
        $this->noCarrinho(200);

        $this->get('/finalizacao');
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->withSession(['checkout_version' => 1])->get('/finalizacao/pagamento')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', null)
            ->where('discountAmount', 0)
            ->where('total', 200)
            ->where('pixDiscountAmount', 4));
    }

    public function test_outro_cupom_tira_o_popup(): void
    {
        Coupon::create(['code' => 'DEZOFF', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10, 'is_active' => true]);
        $this->noCarrinho(200);
        $this->get('/finalizacao');

        $this->postJson('/finalizacao/cupom', ['code' => 'DEZOFF'])->assertOk()->assertJson(['discount_amount' => 20]);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('couponCode', 'DEZOFF')->where('cupomPrimeiraCompra', null));
    }

    public function test_cupom_desligado_nao_tem_popup(): void
    {
        Coupon::buscar('BEMVINDO')->update(['is_active' => false]);
        $this->noCarrinho(200);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('couponCode', null)->where('cupomPrimeiraCompra', null));
    }

    public function test_pix_nao_passa_do_que_sobra_depois_do_cupom(): void
    {
        $this->assertEquals(4.0, DescontoPix::descontoSobreCupom(200, 6, 2));
        $this->assertEquals(2.0, DescontoPix::descontoSobreCupom(200, 198, 2));
    }

    public function test_pagina_de_produto_mostra_so_os_2_por_cento_do_pix_sem_o_cupom(): void
    {
        $product = Product::factory()->create(['price' => 200, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 2, 'discount_percentage' => null, 'discount_amount' => null]);

        $this->assertEquals(round((float) $product->final_price * 0.98, 2), DescontoPix::precoPix($product));
        $this->assertEquals(2.0, DescontoPix::percentualNaVitrine($product));
    }

    public function test_ferrakora_so_tem_cupom_quando_o_site_manda(): void
    {
        Mail::fake();
        Bus::fake();
        $product = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 2, 'discount_percentage' => null, 'discount_amount' => null]);
        FerraKoraProduto::create(['product_id' => $product->id, 'categoria' => 'Trenas', 'ordem' => 1, 'ativo' => true]);
        $itens = [['variante' => 'kzv-'.$product->id, 'quantidade' => 2]];

        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $itens])
            ->assertOk()
            ->assertJsonPath('cupom', null)
            ->assertJsonPath('cupom_boas_vindas.codigo', 'BEMVINDO')
            ->assertJsonPath('cupom_boas_vindas.descricao', '3% de desconto')
            ->assertJsonPath('total', 20000)
            ->assertJsonPath('total_pix', 19600);

        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $itens, 'cupom' => 'BEMVINDO'])
            ->assertOk()
            ->assertJsonPath('cupom.codigo', 'BEMVINDO')
            ->assertJsonPath('cupom_boas_vindas', null)
            ->assertJsonPath('desconto_cupom', 600)
            ->assertJsonPath('total', 19400)
            ->assertJsonPath('desconto_pix', 400)
            ->assertJsonPath('total_pix', 19000);
    }

    private function mockPix(): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 77, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });
    }
}
