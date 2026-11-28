<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\CotarFreteDoPedidoJob;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\LeveDois;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\DescontoPix;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Compre 1, o 2º sai por R$ X" (pedido do Lira em 27/11/2026): cupom do dia
 * LEVE2+DDMM, 2ª unidade pelo custo + frete incremental + taxas + R$ 20, com
 * registro no pedido. Fluxo completo do checkout até criar o pedido.
 *
 * Conta do produto padrão (preço 150, custo 80, sem peso = 1 kg, sem Pix):
 *   (80 + 25 de frete + 20 de lucro) / (1 − 4,98% cartão − 4,5% imposto)
 *   = 125 / 0,9052 = 138,09 → 138,90
 */
class LeveDoisTest extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->withoutVite();
        Bus::fake([CotarFreteDoPedidoJob::class]);
        config(['loja.simples.aliquota' => 4.5, 'ofertas.taxa_cartao' => 4.98, 'ofertas.taxa_pix' => 0.99, 'ofertas.frete_estimado' => 25, 'leve_dois.lucro' => 20, 'leve_dois.frete_por_kg' => null]);
        Carbon::setTestNow(Carbon::parse('2026-11-28 10:00', 'America/Sao_Paulo'));
        $this->frete = ShippingMethod::factory()->create(['price' => 25, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function produto(float $preco = 150, float $custo = 80, float $pix = 0): Product
    {
        return Product::factory()->create(['price' => $preco, 'cost_price' => $custo, 'stock' => 10, 'is_active' => true, 'checkout_discount_percentage' => $pix, 'discount_percentage' => null, 'discount_amount' => null]);
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

    private function pagar(): Order
    {
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        return Order::with('items')->firstOrFail();
    }

    public function test_preco_da_segunda_unidade_pela_conta_de_custos(): void
    {
        // Produto de 2,5 kg (antes dos outros: o mapa de pesos fica em cache): frete incremental
        // 3 × 25 = 75 → (80 + 75 + 20) / 0,9052 = 193,33 → 193,90.
        $pesado = $this->produto(preco: 300);
        $pesado->fiscalData()->create(['peso_bruto' => 2.5]);
        $this->assertSame(75.0, LeveDois::freteIncremental($pesado));
        $this->assertSame(193.90, LeveDois::precoSegunda($pesado));

        $this->assertSame(138.90, LeveDois::precoSegunda($this->produto()));
        // Com 5% no Pix o pior caso passa a ser o Pix: 125 / (0,95 × 0,9901 − 0,045) = 139,57 → 139,90.
        $this->assertSame(139.90, LeveDois::precoSegunda($this->produto(pix: 5)));
    }

    public function test_arredonda_para_cima_em_90_ou_99(): void
    {
        $this->assertSame(107.90, LeveDois::arredondar(107.23));
        $this->assertSame(107.90, LeveDois::arredondar(107.90));
        $this->assertSame(107.99, LeveDois::arredondar(107.95));
        $this->assertSame(108.90, LeveDois::arredondar(107.991));
        $this->assertSame(108.90, LeveDois::arredondar(108.00));
    }

    public function test_fluxo_completo_com_o_cupom_do_dia_e_registro_no_pedido(): void
    {
        $this->artisan('loja:cupom-leve2')->assertSuccessful();
        $this->assertNotNull(Coupon::buscar('LEVE22811'));
        $produto = $this->produto();
        $this->mockPix();

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao')->assertOk();
        $this->postJson('/finalizacao/cupom', ['code' => 'leve22811'])->assertOk()
            ->assertJson(['code' => 'LEVE22811', 'discount_amount' => 11.10, 'pix_depois_do_cupom' => true]);
        $order = $this->pagar();

        $this->assertSame('LEVE22811', $order->coupon_code);
        $this->assertEquals(300, (float) $order->subtotal);
        $this->assertEquals(11.10, (float) $order->discount_amount);
        $this->assertEquals(round(300 - 11.10 + (float) $order->shipping_cost, 2), (float) $order->total);
        $item = $order->items->first();
        $this->assertSame(1, $item->leve2_unidades);
        $this->assertEquals(150, (float) $item->leve2_preco_normal);
        $this->assertEquals(138.90, (float) $item->leve2_preco);
        $this->assertEquals(11.10, (float) $item->leve2_desconto);

        // Admin do pedido mostra o registro.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('detalhe.valores.cupom', 'LEVE22811')
                ->where('detalhe.itens.0.leve2.unidades', 1)
                ->where('detalhe.itens.0.leve2.preco', 138.9)
                ->where('detalhe.itens.0.leve2.desconto', 11.1)
                ->etc());
    }

    public function test_cada_par_ganha_o_preco_e_a_unidade_impar_fica_normal(): void
    {
        $this->artisan('loja:cupom-leve2');
        $produto = $this->produto();

        $conta = LeveDois::conta([['product' => $produto, 'quantity' => 5, 'subtotal' => 750.0]]);

        // 5 unidades = 2 pares: 3 × 150 + 2 × 138,90 = 727,80.
        $this->assertSame(2, $conta['itens'][$produto->id]['unidades']);
        $this->assertEquals(22.20, $conta['total']);
    }

    public function test_pix_vale_sobre_o_que_sobra_depois_do_cupom(): void
    {
        $this->artisan('loja:cupom-leve2');
        $produto = $this->produto(pix: 5);
        $this->mockPix();

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22811'])->assertOk()->assertJson(['discount_amount' => 10.10]);
        $order = $this->pagar();

        $pix = DescontoPix::desconto(300 - 10.10, 5);
        $this->assertEquals($pix, (float) $order->pix_discount_amount);
        $this->assertEquals(round(300 - 10.10 - $pix + (float) $order->shipping_cost, 2), (float) $order->total);
    }

    public function test_sem_cupom_o_pedido_sai_pelo_preco_normal(): void
    {
        $this->artisan('loja:cupom-leve2');
        $produto = $this->produto();
        $this->mockPix();

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao');
        $order = $this->pagar();

        $this->assertNull($order->coupon_code);
        $this->assertEquals(0, (float) $order->discount_amount);
        $this->assertEquals(round(300 + (float) $order->shipping_cost, 2), (float) $order->total);
        $this->assertNull($order->items->first()->leve2_unidades);
    }

    public function test_cupom_de_ontem_e_recusado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27 23:30', 'America/Sao_Paulo'));
        $this->artisan('loja:cupom-leve2');
        Carbon::setTestNow(Carbon::parse('2026-11-28 00:00', 'America/Sao_Paulo'));
        $this->artisan('loja:cupom-leve2');

        $this->assertFalse(Coupon::buscar('LEVE22711')->is_active);
        $this->assertTrue(Coupon::buscar('LEVE22811')->is_active);

        $produto = $this->produto();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22711'])->assertStatus(422);

        // Mesmo ativo, a data de ontem já passou.
        Coupon::buscar('LEVE22711')->update(['is_active' => true]);
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22711'])->assertStatus(422)->assertJsonPath('errors.code.0', 'Este cupom expirou em 27/11/2026.');
    }

    public function test_produto_sem_custo_fica_fora(): void
    {
        $this->artisan('loja:cupom-leve2');
        $produto = $this->produto(custo: 0);

        $this->assertNull(LeveDois::precoSegunda($produto));
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22811'])->assertStatus(422);
    }

    public function test_segunda_unidade_que_nao_fica_mais_barata_fica_fora(): void
    {
        $this->artisan('loja:cupom-leve2');
        // 120 de preço com custo 80: a 2ª sairia por 138,90, mais que o normal.
        $produto = $this->produto(preco: 120);

        $this->assertNull(LeveDois::precoSegunda($produto));
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22811'])->assertStatus(422);
    }

    public function test_uma_unidade_so_nao_aceita_o_cupom(): void
    {
        $this->artisan('loja:cupom-leve2');
        $produto = $this->produto();

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22811'])->assertStatus(422);
    }

    public function test_quantidade_baixou_depois_de_aplicar_o_pedido_sai_sem_o_cupom(): void
    {
        $this->artisan('loja:cupom-leve2');
        $produto = $this->produto();
        $this->mockPix();

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22811'])->assertOk();
        $this->patchJson("/finalizacao/itens/{$produto->id}", ['quantity' => 1])->assertOk();
        $order = $this->pagar();

        $this->assertNull($order->coupon_code);
        $this->assertEquals(round(150 + (float) $order->shipping_cost, 2), (float) $order->total);
    }

    public function test_outros_cupons_continuam_iguais(): void
    {
        Coupon::create(['code' => 'DEZ', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
        $produto = $this->produto();

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/cupom', ['code' => 'DEZ'])->assertOk()->assertJson(['discount_amount' => 30, 'pix_depois_do_cupom' => false]);
    }

    public function test_comando_nao_duplica_nem_reativa_o_cupom_desligado_a_mao(): void
    {
        $this->artisan('loja:cupom-leve2');
        Coupon::buscar('LEVE22811')->update(['is_active' => false]);
        $this->artisan('loja:cupom-leve2');

        $this->assertSame(1, Coupon::query()->where('segunda_unidade', true)->count());
        $this->assertFalse(Coupon::buscar('LEVE22811')->is_active);
        $this->assertNull(LeveDois::cupomDoDia());
    }

    public function test_pagina_do_produto_mostra_a_promocao_com_o_cupom_do_dia(): void
    {
        $produto = $this->produto();
        $this->get("/produtos/{$produto->slug}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('leveDois', null)->etc());

        $this->artisan('loja:cupom-leve2');
        $this->get("/produtos/{$produto->slug}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('leveDois.code', 'LEVE22811')
                ->where("leveDois.precos.{$produto->id}", 138.9)
                ->etc());
    }

    public function test_endpoint_publico_com_o_codigo_do_dia_e_os_produtos(): void
    {
        $this->artisan('loja:cupom-leve2');
        $dentro = $this->produto();
        $this->produto(preco: 120);
        $this->produto(custo: 0);

        $resposta = $this->getJson('/api/promocao-do-dia')->assertOk()
            ->assertJsonPath('data.cupom', 'LEVE22811')
            ->assertJsonCount(1, 'data.produtos')
            ->assertJsonPath('data.produtos.0.id', $dentro->id)
            ->assertJsonPath('data.produtos.0.preco_segunda_unidade', 138.9)
            ->assertJsonPath('data.produtos.0.preco_normal', 150);

        $this->assertStringNotContainsString('cost', $resposta->getContent());
        $this->assertStringNotContainsString('custo', $resposta->getContent());
    }
}
