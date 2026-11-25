<?php

namespace Tests\Feature\Checkout;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Support\OrderBump;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Order bump do checkout (pedido do Lira 2026-11-24): escolha da oferta,
 * preço sempre do servidor com margem mínima, item marcado no pedido, fora do
 * cupom e do Pix, e a medição de exibição e aceite.
 */
class OrderBumpTest extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
    }

    private function produto(array $dados = []): Product
    {
        return Product::factory()->create([
            'price' => 100, 'cost_price' => 30, 'stock' => 10, 'is_active' => true, 'so_ferrakora' => false,
            'discount_percentage' => null, 'discount_amount' => null, 'checkout_discount_percentage' => null, ...$dados,
        ]);
    }

    private function noCarrinho(Product $product, int $quantidade = 1): void
    {
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => $quantidade]);
    }

    private function pedido(array $dados): Order
    {
        return Order::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CUSTOMER])->id,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 10, 'total' => 10, ...$dados,
        ]);
    }

    private function vendido(Product $product, int $quantidade): void
    {
        $order = $this->pedido(['status' => Order::STATUS_PAID]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => $quantidade, 'subtotal' => 10 * $quantidade]);
    }

    private function entregaECliente(): void
    {
        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $this->frete->id,
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(11) 99999-0000', 'zip' => '03187-040',
                'street' => 'Rua Mogi Mirim', 'number' => '20', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ])->assertOk();
    }

    private function mockPix(?float &$cobrado = null): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use (&$cobrado) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->andReturnUsing(function ($amount) use (&$cobrado) {
                $cobrado = $amount;

                static $id = 90;

                return ['id' => ++$id, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'x', 'qr_code_base64' => 'y']]];
            });
        });
    }

    public function test_escolha_manual_do_cadastro_com_o_desconto_do_produto(): void
    {
        $bump = $this->produto(['price' => 40, 'cost_price' => 10]);
        $principal = $this->produto(['price' => 200, 'order_bump_product_id' => $bump->id, 'order_bump_desconto_pct' => 20]);
        // Mais vendido existe, mas a escolha do cadastro vem antes.
        $this->vendido($this->produto(['price' => 30]), 50);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]));
        $this->noCarrinho($principal);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('orderBump.product_id', $bump->id)
            ->where('orderBump.preco_de', 40)
            ->where('orderBump.preco', 32)
            ->where('orderBump.desconto_pct', 20)
            ->where('orderBumpMarcado', false));
    }

    public function test_variacao_sem_escolha_usa_a_do_anuncio_pai(): void
    {
        $bump = $this->produto(['price' => 40, 'cost_price' => 10]);
        $pai = $this->produto(['price' => 200, 'order_bump_product_id' => $bump->id]);
        $variacao = $this->produto(['price' => 200, 'parent_product_id' => $pai->id]);

        $oferta = app(OrderBump::class)->oferta(collect([['product' => $variacao, 'quantity' => 1, 'subtotal' => 200.0]]));

        $this->assertSame($bump->id, $oferta['product']->id);
        $this->assertSame(OrderBump::ESCOLHA_PRODUTO, $oferta['escolha']);
        $this->assertSame(34.0, $oferta['preco']);
    }

    public function test_produto_geral_do_admin_vem_antes_do_automatico(): void
    {
        $geral = $this->produto(['price' => 50]);
        $this->vendido($this->produto(['price' => 30]), 50);
        app(OrderBump::class)->configurar(true, $geral->id, 10);
        $principal = $this->produto(['price' => 200]);

        $oferta = app(OrderBump::class)->oferta(collect([['product' => $principal, 'quantity' => 1, 'subtotal' => 200.0]]));

        $this->assertSame($geral->id, $oferta['product']->id);
        $this->assertSame(45.0, $oferta['preco']);
    }

    public function test_automatico_pega_o_mais_vendido_que_cabe_e_empata_pelo_mais_barato(): void
    {
        $principal = $this->produto(['price' => 200]);
        $caro = $this->produto(['price' => 90]);          // acima de 35% de 200 (= 70)
        $vendeMais = $this->produto(['price' => 60]);
        $empateBarato = $this->produto(['price' => 50]);
        $empateCaro = $this->produto(['price' => 55]);
        $semEstoque = $this->produto(['price' => 20, 'stock' => 0]);
        $soFerra = $this->produto(['price' => 20, 'so_ferrakora' => true]);
        $semCusto = $this->produto(['price' => 20, 'cost_price' => null]);
        $this->vendido($caro, 100);
        $this->vendido($semEstoque, 100);
        $this->vendido($soFerra, 100);
        $this->vendido($semCusto, 100);
        $this->vendido($vendeMais, 9);
        $this->vendido($empateBarato, 5);
        $this->vendido($empateCaro, 5);

        $bump = app(OrderBump::class);
        $carrinho = collect([['product' => $principal, 'quantity' => 1, 'subtotal' => 200.0]]);
        $this->assertSame($vendeMais->id, $bump->oferta($carrinho)['product']->id);
        $this->assertSame(OrderBump::ESCOLHA_AUTOMATICA, $bump->oferta($carrinho)['escolha']);
        $this->assertSame(51.0, $bump->oferta($carrinho)['preco']);

        $vendeMais->update(['stock' => 0]);
        $this->assertSame($empateBarato->id, $bump->oferta($carrinho)['product']->id);
    }

    public function test_sem_nada_que_sirva_nao_mostra_a_caixa(): void
    {
        $principal = $this->produto(['price' => 40]);
        $this->produto(['price' => 500]); // acima do teto
        $this->produto(['price' => 15, 'cost_price' => null]); // sem custo
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]));
        $this->noCarrinho($principal);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('orderBump', null));

        // Desligado no admin: também some.
        $this->produto(['price' => 15]);
        app(OrderBump::class)->configurar(false, null, 15);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('orderBump', null));
    }

    public function test_carrinho_com_o_proprio_produto_ou_variacao_dele_nao_oferece(): void
    {
        $bump = $this->produto(['price' => 40]);
        $irma = $this->produto(['price' => 40, 'parent_product_id' => $bump->id]);
        $principal = $this->produto(['price' => 200, 'order_bump_product_id' => $bump->id]);
        $servico = app(OrderBump::class);

        $soComBump = collect([
            ['product' => $principal, 'quantity' => 1, 'subtotal' => 200.0],
            ['product' => $bump, 'quantity' => 1, 'subtotal' => 40.0],
        ]);
        $this->assertNotSame($bump->id, $servico->oferta($soComBump)['product']->id ?? null);
        $this->assertNotSame($irma->id, $servico->oferta($soComBump)['product']->id ?? null);

        $comVariacao = collect([
            ['product' => $principal, 'quantity' => 1, 'subtotal' => 200.0],
            ['product' => $irma, 'quantity' => 1, 'subtotal' => 40.0],
        ]);
        $this->assertNotSame($bump->id, $servico->oferta($comVariacao)['product']->id ?? null);
    }

    public function test_margem_minima_reduz_o_desconto_e_sem_margem_nao_oferece(): void
    {
        $principal = $this->produto(['price' => 300]);
        $servico = app(OrderBump::class);

        // Custo 70: piso = 70 x 1,3 = 91. 15% de 100 daria 85, então fica 91 (9%).
        $apertado = $this->produto(['price' => 100, 'cost_price' => 70]);
        $principal->update(['order_bump_product_id' => $apertado->id]);
        $oferta = $servico->oferta(collect([['product' => $principal->fresh(), 'quantity' => 1, 'subtotal' => 300.0]]));
        $this->assertSame($apertado->id, $oferta['product']->id);
        $this->assertSame(91.0, $oferta['preco']);
        $this->assertSame(9.0, $oferta['desconto_pct']);

        // Custo 80: piso 104 > preço cheio 100: não oferece este (cai no automático, que não tem nada).
        $semMargem = $this->produto(['price' => 100, 'cost_price' => 80]);
        $principal->update(['order_bump_product_id' => $semMargem->id]);
        $apertado->update(['stock' => 0]);
        $this->assertNull($servico->oferta(collect([['product' => $principal->fresh(), 'quantity' => 1, 'subtotal' => 300.0]])));
    }

    public function test_pedido_com_bump_usa_o_preco_do_servidor_marca_o_item_e_baixa_o_estoque(): void
    {
        $bump = $this->produto(['price' => 40, 'cost_price' => 10, 'stock' => 3]);
        $principal = $this->produto(['price' => 200, 'order_bump_product_id' => $bump->id]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => '529.982.247-25']));
        $this->noCarrinho($principal);
        $this->entregaECliente();
        $cobrado = null;
        $this->mockPix($cobrado);

        // O navegador manda só o id; qualquer preço que viesse junto seria ignorado.
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true, 'order_bump_id' => $bump->id, 'order_bump_preco' => 1])
            ->assertOk();

        $order = Order::with('items')->firstOrFail();
        $item = $order->items->firstWhere('product_id', $bump->id);
        $this->assertSame(OrderItem::ORIGEM_ORDER_BUMP, $item->origem);
        $this->assertEquals(34.0, (float) $item->product_price);
        $this->assertNull($order->items->firstWhere('product_id', $principal->id)->origem);
        $this->assertEquals(234.0, (float) $order->subtotal);
        // Pix (5% do produto) só sobre o carrinho, nunca sobre o bump.
        $this->assertEquals(0.0, (float) $order->pix_discount_amount);
        $this->assertEquals(234.0, (float) $order->total);
        $this->assertEquals(234.0, $cobrado);
        $this->assertSame(2, $bump->fresh()->stock);
    }

    public function test_total_sem_bump_nao_muda_e_cupom_nao_vale_no_bump(): void
    {
        Coupon::create(['code' => 'DEZOFF', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10, 'is_active' => true]);
        $bump = $this->produto(['price' => 40, 'cost_price' => 10]);
        $principal = $this->produto(['price' => 200, 'order_bump_product_id' => $bump->id, 'checkout_discount_percentage' => 5]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => '529.982.247-25']));
        $this->noCarrinho($principal);
        $this->post('/finalizacao/pagamento/cupom', ['code' => 'DEZOFF']);
        $this->entregaECliente();
        $this->mockPix();

        // Sem bump: 200 - 20 (cupom), Pix limitado ao que sobrou (5% de 200 = 10).
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();
        $semBump = Order::latest('id')->firstOrFail();
        $this->assertEquals(170.0, (float) $semBump->total);
        $this->assertSame(0, $semBump->items()->where('origem', OrderItem::ORIGEM_ORDER_BUMP)->count());

        // Com bump: soma só os 34 do bump; cupom e Pix ficam iguais.
        Order::query()->update(['status' => Order::STATUS_CANCELLED]);
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true, 'order_bump_id' => $bump->id])->assertOk();
        $comBump = Order::latest('id')->firstOrFail();
        $this->assertNotSame($semBump->id, $comBump->id);
        $this->assertEquals(204.0, (float) $comBump->total);
        $this->assertEquals((float) $semBump->discount_amount, (float) $comBump->discount_amount);
    }

    public function test_bump_que_nao_e_mais_a_oferta_volta_sem_criar_pedido(): void
    {
        $bump = $this->produto(['price' => 40, 'cost_price' => 10]);
        $outro = $this->produto(['price' => 40, 'cost_price' => 10]);
        $principal = $this->produto(['price' => 200, 'order_bump_product_id' => $bump->id]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => '529.982.247-25']));
        $this->noCarrinho($principal);
        $this->entregaECliente();
        $this->mockPix();

        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true, 'order_bump_id' => $outro->id])
            ->assertRedirect(route('finalizacao.entrega'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Order::count());
    }

    public function test_medicao_de_exibicao_e_aceite_e_tela_do_admin(): void
    {
        $bump = $this->produto(['price' => 40, 'cost_price' => 10]);
        $principal = $this->produto(['price' => 200, 'order_bump_product_id' => $bump->id]);
        $this->noCarrinho($principal);

        $this->postJson('/finalizacao/order-bump', ['acao' => 'exibido', 'product_id' => $bump->id])->assertOk();
        $this->postJson('/finalizacao/order-bump', ['acao' => 'exibido', 'product_id' => $bump->id])->assertOk();
        $this->postJson('/finalizacao/order-bump', ['acao' => 'marcou', 'product_id' => $principal->id])->assertStatus(409);
        $this->postJson('/finalizacao/order-bump', ['acao' => 'marcou', 'product_id' => $bump->id])->assertOk()->assertJsonPath('order_bump.preco', 34);
        $this->assertSame($bump->id, session('checkout_draft.order_bump'));

        $this->assertSame(1, DB::table('order_bump_eventos')->where('tipo', 'exibido')->count());
        $this->assertSame(1, DB::table('order_bump_eventos')->where('tipo', 'marcou')->count());

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('orderBumpMarcado', true));

        $pedido = $this->pedido(['status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE, 'site_origem' => null, 'total' => 234]);
        $pedido->items()->create(['product_id' => $bump->id, 'product_name' => 'Bump', 'product_price' => 34, 'quantity' => 1, 'subtotal' => 34, 'origem' => OrderItem::ORIGEM_ORDER_BUMP]);
        $this->pedido(['status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE, 'site_origem' => null, 'total' => 100]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/order-bump')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/OrderBump/Index', false)
            ->where('resumo.exibicoes', 1)
            ->where('resumo.aceites', 1)
            ->where('resumo.pedidos_com_bump', 1)
            ->where('resumo.receita_extra', 34)
            ->where('resumo.ticket_com_bump', 234)
            ->where('resumo.ticket_sem_bump', 100));

        $this->actingAs($admin)->put('/admin/order-bump', ['ligado' => false, 'produto_id' => null, 'desconto_pct' => 12])->assertRedirect();
        $this->assertSame('0', Setting::get(OrderBump::CHAVE_LIGADO));
        $this->assertSame(12.0, app(OrderBump::class)->descontoPadrao());
    }

    public function test_cadastro_do_produto_grava_o_order_bump(): void
    {
        $bump = $this->produto(['price' => 40]);
        $produto = $this->produto();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get("/admin/produtos/{$produto->id}/editar")->assertInertia(fn (AssertableInertia $page) => $page->has('produtosOrderBump'));
        $this->actingAs($admin)->put("/admin/produtos/{$produto->id}", [
            'name' => $produto->name, 'price' => 100, 'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
            'order_bump_product_id' => $bump->id, 'order_bump_desconto_pct' => 12.5,
        ])->assertSessionHasNoErrors();

        $this->assertSame($bump->id, $produto->fresh()->order_bump_product_id);
        $this->assertEquals(12.5, (float) $produto->fresh()->order_bump_desconto_pct);

        // Não pode escolher o próprio produto.
        $this->actingAs($admin)->put("/admin/produtos/{$produto->id}", [
            'name' => $produto->name, 'price' => 100, 'is_active' => true, 'is_featured' => false, 'is_new_release' => false,
            'order_bump_product_id' => $produto->id,
        ])->assertSessionHasErrors('order_bump_product_id');
    }
}
