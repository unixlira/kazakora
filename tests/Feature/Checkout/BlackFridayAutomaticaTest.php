<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\BlackFriday;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Black Friday automática (pedido do Lira 2026-11-24): o BLACKFRIDAY (5%)
 * entra sozinho no carrinho e no checkout até loja.blackfriday_ate, e o
 * preço "com cupom" e "no Pix" da página do produto é exatamente o total do
 * checkout. Passou a data, tudo desliga sozinho.
 */
class BlackFridayAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->withoutVite();
        config(['loja.cupom_faixa' => 'BLACKFRIDAY', 'loja.blackfriday_ate' => '2026-11-30 23:59:59']);
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'America/Sao_Paulo'));
        // A migração cria o BLACKFRIDAY de 5%, só KazaKora.
        $this->assertNotNull(Coupon::buscar('BLACKFRIDAY'));
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
    }

    private function travesseiro(array $extra = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Travesseiro', 'slug' => 'travesseiro', 'price' => 206.98, 'stock' => 10, 'is_active' => true,
            'checkout_discount_percentage' => 2, 'checkout_discount_amount' => null, 'discount_percentage' => null, 'discount_amount' => null,
        ], $extra));
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

    private static function igual(float $esperado): \Closure
    {
        return fn ($valor) => abs((float) $valor - $esperado) < 0.001;
    }

    public function test_exemplo_do_travesseiro_de_206_98_por_196_63_e_192_49_no_pix(): void
    {
        $this->travesseiro();

        $this->get('/produtos/travesseiro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.preco_cupom.code', 'BLACKFRIDAY')
            ->where('product.preco_cupom.de', self::igual(206.98))
            ->where('product.preco_cupom.cupom', self::igual(10.35))
            ->where('product.preco_cupom.por', self::igual(196.63))
            ->where('product.preco_cupom.pix', self::igual(192.49))
            ->where('product.precos_cupom.1.por', self::igual(196.63))
            ->where('ofertaPadrao.texto', 'Cupom BLACKFRIDAY já aplicado no seu pedido. Válido até 30/11.')
            ->where('blackfriday.rotulo', '5% OFF'));
    }

    public function test_preco_da_pagina_e_o_total_do_checkout_com_o_cupom_automatico(): void
    {
        $produto = $this->travesseiro();
        $precos = BlackFriday::precos($produto->fresh());
        $this->assertNull($precos, 'Fora da loja (sem site) não há preço de Black Friday.');

        // Entrou no carrinho: o cupom já está no rascunho, sem ninguém digitar.
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->assertSame('BLACKFRIDAY', session('checkout_draft.coupon_code'));

        $this->get('/carrinho')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cupomBlackFriday.code', 'BLACKFRIDAY')
            ->where('cupomBlackFriday.valor', self::igual(10.35)));

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Checkout/CheckoutV2', false)
            ->where('subtotal', self::igual(206.98))
            ->where('couponCode', 'BLACKFRIDAY')
            ->where('discountAmount', self::igual(10.35))
            ->where('cupomFaixa.auto', true)
            ->where('cupomFaixa.rotulo', '5% OFF'));

        $this->mockPix();
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('BLACKFRIDAY', $order->coupon_code);
        $this->assertEquals(192.49, (float) $order->total);
        $this->assertEquals(4.14, (float) $order->pix_discount_amount);
    }

    public function test_link_direto_do_checkout_e_cartao_cobram_o_preco_por(): void
    {
        $produto = $this->travesseiro();

        // Comprar agora: vai direto para o checkout, que aplica o cupom ao abrir.
        $this->post('/comprar-agora', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->withSession(['checkout_version' => 1])->get('/finalizacao/pagamento')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', 'BLACKFRIDAY')
            ->where('total', self::igual(196.63))
            ->where('pixDiscountAmount', self::igual(4.14)));
    }

    public function test_kit_soma_o_desconto_do_kit_com_o_cupom(): void
    {
        $produto = $this->travesseiro(['price' => 100]);
        $produto->quantityDiscounts()->create(['min_quantity' => 2, 'discount_percentage' => 10]);

        // 2 unidades a 90 = 180; cupom 5% = 9; por 171; Pix 2% de 180 = 3,60 → 167,40.
        $this->get('/produtos/travesseiro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.precos_cupom.2.de', self::igual(180))
            ->where('product.precos_cupom.2.cupom', self::igual(9))
            ->where('product.precos_cupom.2.por', self::igual(171))
            ->where('product.precos_cupom.2.pix', self::igual(167.40)));

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->mockPix();
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subtotal', self::igual(180))
            ->where('discountAmount', self::igual(9)));
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $this->assertEquals(167.40, (float) Order::firstOrFail()->total);
    }

    public function test_tirou_o_cupom_nao_volta_sozinho_e_aplica_de_novo_com_um_clique(): void
    {
        $produto = $this->travesseiro();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $this->deleteJson('/finalizacao/cupom')->assertOk();
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', null)
            ->where('cupomFaixa.auto', true));
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->assertNull(session('checkout_draft.coupon_code'));

        // Botão "Aplicar de novo" da faixa.
        $this->postJson('/finalizacao/cupom', ['code' => 'BLACKFRIDAY'])->assertOk()->assertJson(['code' => 'BLACKFRIDAY']);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('couponCode', 'BLACKFRIDAY'));
    }

    public function test_outro_cupom_fica_no_lugar_sem_acumular(): void
    {
        Coupon::create(['code' => 'DEZOFF', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10, 'is_active' => true]);
        $produto = $this->travesseiro(['price' => 200]);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $this->postJson('/finalizacao/cupom', ['code' => 'DEZOFF'])->assertOk()->assertJson(['discount_amount' => 20]);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', 'DEZOFF')
            ->where('discountAmount', self::igual(20)));
    }

    public function test_produto_em_oferta_do_dia_nao_ganha_o_cupom(): void
    {
        $produto = $this->travesseiro(['price' => 100]);
        OfertaDoDia::create(['product_id' => $produto->id, 'data' => now()->toDateString(), 'posicao' => 1, 'desconto_extra' => 10, 'preco_antes' => 100, 'preco_oferta' => 90, 'lucro_estimado' => 10]);

        $this->get('/produtos/travesseiro')->assertInertia(fn (AssertableInertia $page) => $page->where('product.preco_cupom', null));
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->assertNull(session('checkout_draft.coupon_code'));
    }

    public function test_desliga_sozinho_depois_de_30_11_as_23_59(): void
    {
        $produto = $this->travesseiro();

        $this->travelTo(Carbon::parse('2026-11-30 23:59:00', 'America/Sao_Paulo'));
        $this->get('/produtos/travesseiro')->assertInertia(fn (AssertableInertia $page) => $page->where('product.preco_cupom.por', self::igual(196.63)));

        $this->travelTo(Carbon::parse('2026-12-01 00:00:01', 'America/Sao_Paulo'));
        $this->get('/produtos/travesseiro')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.preco_cupom', null)
            ->where('product.precos_cupom', null)
            ->where('blackfriday', null)
            ->where('ofertaPadrao.tipo', 'pix'));

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->assertNull(session('checkout_draft.coupon_code'));
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', null)
            ->where('cupomFaixa', null));
    }

    public function test_cupom_que_entrou_sozinho_sai_se_a_bf_acabar_com_o_checkout_aberto(): void
    {
        $produto = $this->travesseiro();
        $this->travelTo(Carbon::parse('2026-11-30 23:50:00', 'America/Sao_Paulo'));
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();

        $this->travelTo(Carbon::parse('2026-12-01 00:05:00', 'America/Sao_Paulo'));
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldNotReceive('createPixPayment');
        });
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])
            ->assertRedirect()->assertSessionHasErrors('code');

        $this->assertSame(0, Order::count());
        $this->assertNull(session('checkout_draft.coupon_code'));
    }
}
