<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\CotarFreteDoPedidoJob;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\LojaShippingDriver;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\ChannelShippingService;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Cupom de entrega em mãos (pedido do Lira em 27/11/2026): venda que o Lira
 * entrega pessoalmente. O pedido não cobra frete e não gera etiqueta. O
 * cupom pode ter desconto (15%, 20%) ou nenhum.
 */
class CupomEntregaEmMaosTest extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->withoutVite();
        config(['frete_embutido.ligado' => false]);
        $this->frete = ShippingMethod::factory()->create(['price' => 25, 'is_active' => true]);
    }

    private function noCarrinho(float $preco = 100): Product
    {
        $product = Product::factory()->create(['price' => $preco, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 0, 'discount_percentage' => null, 'discount_amount' => null]);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 2]);

        return $product;
    }

    private function entrega(): array
    {
        return [
            'shipping_method_id' => $this->frete->id,
            'guest' => ['name' => 'Martha Cliente', 'email' => 'martha@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 97532-0000'],
            'new_address' => [
                'recipient_name' => 'Martha Cliente', 'phone' => '(11) 97532-0000', 'zip' => '03187-040',
                'street' => 'Rua Mogi Mirim', 'number' => '20', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ];
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

    public function test_cupom_sem_desconto_tira_o_frete_e_marca_o_pedido_sem_etiqueta(): void
    {
        Bus::fake([CotarFreteDoPedidoJob::class]);
        Coupon::create(['code' => 'MAOSLIVROS', 'discount_type' => 'percentage', 'discount_value' => 0, 'is_active' => true, 'entrega_em_maos' => true]);
        $this->noCarrinho(100);
        $this->mockPix();

        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'maoslivros'])->assertOk()
            ->assertJson(['code' => 'MAOSLIVROS', 'discount_amount' => 0, 'descricao' => 'Entrega em mãos, sem frete']);
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::firstOrFail();
        $this->assertTrue($order->entrega_em_maos);
        $this->assertEquals(0, (float) $order->shipping_cost);
        $this->assertSame(Order::ENTREGA_EM_MAOS, $order->shipping_carrier_name);
        $this->assertEquals(200, (float) $order->total);
        Bus::assertNotDispatched(CotarFreteDoPedidoJob::class);
    }

    public function test_cupom_com_desconto_e_entrega_em_maos(): void
    {
        Bus::fake([CotarFreteDoPedidoJob::class]);
        Coupon::create(['code' => 'MAOS20', 'discount_type' => 'percentage', 'discount_value' => 20, 'is_active' => true, 'entrega_em_maos' => true]);
        $this->noCarrinho(100);
        $this->mockPix();

        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'MAOS20'])->assertOk()->assertJson(['discount_amount' => 40, 'descricao' => '20% de desconto + entrega em mãos']);
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::firstOrFail();
        $this->assertTrue($order->entrega_em_maos);
        $this->assertEquals(160, (float) $order->total);
    }

    public function test_pedido_de_entrega_em_maos_nao_gera_etiqueta(): void
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE, 'entrega_em_maos' => true,
            'shipping_name' => 'Martha', 'shipping_phone' => '11975320000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 200, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 200,
        ]);
        $this->mock(LojaShippingDriver::class, fn ($mock) => $mock->shouldNotReceive('confirmShipping'));

        $envio = app(ChannelShippingService::class)->confirm($order);

        $this->assertSame(ChannelShipment::STATUS_CONFIRMED, $envio->status);
        $this->assertSame(Order::ENTREGA_EM_MAOS, $envio->shipping_method);
        $this->assertNull($envio->tracking_code);
    }

    public function test_cupom_normal_continua_cobrando_frete(): void
    {
        Coupon::create(['code' => 'DEZ', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
        $this->noCarrinho(100);
        $this->mockPix();

        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'DEZ'])->assertOk();
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $this->assertFalse(Order::firstOrFail()->entrega_em_maos);
    }

    public function test_admin_cria_cupom_de_entrega_em_maos_sem_desconto(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/cupons', [
            'code' => 'maos-livros', 'name' => 'Martha', 'discount_type' => 'percentage', 'discount_value' => 0,
            'max_uses' => 1, 'one_per_customer' => false, 'is_active' => true, 'entrega_em_maos' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Coupon::buscar('MAOS-LIVROS')->entrega_em_maos);

        // Sem entrega em mãos, desconto 0 continua recusado.
        $this->actingAs($admin)->post('/admin/cupons', [
            'code' => 'zero', 'discount_type' => 'percentage', 'discount_value' => 0, 'is_active' => true,
        ])->assertSessionHasErrors('discount_value');
    }
}
