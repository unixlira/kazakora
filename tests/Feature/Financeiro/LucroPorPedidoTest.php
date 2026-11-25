<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\LucroPorPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Card "Últimos pedidos dos sites" do painel de E-commerces (pedido do Lira
 * em 25/11/2026): lucro líquido de cada pedido com tudo descontado, anúncio
 * do dia rateado entre os pedidos pagos da loja, e a soma fechando com a
 * conta oficial (ContributionMargin::porCanal()).
 */
class LucroPorPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'loja.simples.aliquota' => 4.5, 'loja.simples.desde' => '2026-09-01',
            'loja.insumos_por_pedido' => 2.0,
            'ofertas.taxa_pix' => 0.99, 'ofertas.taxa_cartao' => 4.98, 'ofertas.taxa_boleto' => 3.49,
        ]);
        Carbon::setTestNow('2026-11-25 15:00:00');
    }

    private function pedido(float $subtotal, string $quando, float $custoUnitario, int $quantidade = 1, array $extra = []): Order
    {
        $produto = Product::factory()->create(['price' => $subtotal, 'cost_price' => $custoUnitario, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'maria aparecida souza', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $subtotal, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => $subtotal,
            ...$extra,
        ]);
        $pedido->forceFill(['created_at' => $quando])->save();
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Travesseiro de penas de ganso extra macio', 'product_price' => $subtotal / $quantidade,
            'quantity' => $quantidade, 'subtotal' => $subtotal, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $pedido;
    }

    private function gasto(string $dia, string $canal, float $valor): void
    {
        ChannelAdSpend::create(['date' => $dia, 'channel' => $canal, 'spend' => $valor, 'impressions' => 0, 'clicks' => 0, 'attributed_orders' => 0, 'attributed_gmv' => 0]);
    }

    /** @return array{0: Order, 1: Order} */
    private function cenario(): array
    {
        // A: R$ 200 − R$ 20 de cupom = receita 180; 2 × R$ 50 de custo;
        // Correios R$ 25; taxa real R$ 8,91.
        $a = $this->pedido(200, '2026-11-20 10:00:00', 50, 2, ['discount_amount' => 20, 'total' => 180]);
        CorreiosPrePostagem::create([
            'order_id' => $a->id, 'status' => CorreiosPrePostagem::STATUS_GERADA, 'postage_price' => 25,
            'customer_name' => 'Cliente', 'zip' => '01000000', 'street' => 'Rua X', 'number' => '1',
            'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP',
            'service_code' => '03298', 'weight_grams' => 300, 'content_items' => [],
        ]);
        OrderChannelFee::create(['order_id' => $a->id, 'channel' => 'loja', 'gross_amount' => 180, 'fee_amount' => 8.91, 'source' => OrderChannelFee::SOURCE_REPORT, 'computed_at' => now()]);

        // B: R$ 100 no Pix, sem taxa real ainda (estimada 0,99%).
        $b = $this->pedido(100, '2026-11-20 18:30:00', 40);
        Payment::create(['order_id' => $b->id, 'provider' => 'mercadopago', 'method_type' => Payment::METHOD_PIX, 'amount' => 100, 'status' => Payment::STATUS_CAPTURED]);

        // Pedido aguardando no mesmo dia: não divide o anúncio.
        $this->pedido(300, '2026-11-20 20:00:00', 10, extra: ['status' => Order::STATUS_AWAITING_PAYMENT]);

        // Anúncio do dia da KazaKora: Meta R$ 30 + R$ 10 lançados no canal loja.
        $this->gasto('2026-11-20', 'meta_ads', 30);
        $this->gasto('2026-11-20', 'loja', 10);
        // Outro dia e outra loja não entram.
        $this->gasto('2026-11-21', 'meta_ads', 99);
        $this->gasto('2026-11-20', 'shopee', 50);

        return [$a, $b];
    }

    public function test_lucro_do_pedido_desconta_tudo_e_divide_o_anuncio_do_dia(): void
    {
        [$a, $b] = $this->cenario();
        $linhas = collect(app(LucroPorPedido::class)->pedidos([$a->id, $b->id], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-26')))->keyBy('id');

        $contaA = $linhas[$a->id]['conta'];
        $this->assertEqualsWithDelta(180.0, $contaA['receita'], 0.001);
        $this->assertEqualsWithDelta(100.0, $contaA['produto'], 0.001);
        $this->assertEqualsWithDelta(25.0, $contaA['frete'], 0.001);
        $this->assertEqualsWithDelta(8.91, $contaA['taxa'], 0.001);
        $this->assertFalse($contaA['taxa_estimada']);
        $this->assertEqualsWithDelta(8.10, $contaA['imposto'], 0.001);
        $this->assertEqualsWithDelta(2.0, $contaA['insumos'], 0.001);
        $this->assertEqualsWithDelta(20.0, $contaA['anuncio'], 0.001, 'R$ 40 do dia ÷ 2 pedidos pagos');
        $this->assertSame(2, $contaA['anuncio_pedidos_no_dia']);
        $this->assertEqualsWithDelta(180 - 100 - 25 - 8.91 - 8.10 - 2 - 20, $linhas[$a->id]['lucro'], 0.001);

        $contaB = $linhas[$b->id]['conta'];
        $this->assertTrue($contaB['taxa_estimada']);
        $this->assertEqualsWithDelta(0.99, $contaB['taxa'], 0.001, 'Pix 0,99% de R$ 100');
        $this->assertEqualsWithDelta(20.0, $contaB['anuncio'], 0.001);
        $this->assertEqualsWithDelta(100 - 40 - 0.99 - 4.50 - 2 - 20, $linhas[$b->id]['lucro'], 0.001);

        // Cliente só pelo primeiro nome; itens resumidos.
        $this->assertSame('Maria', $linhas[$a->id]['cliente']);
        $this->assertStringStartsWith('2× Travesseiro', $linhas[$a->id]['itens']);
        $this->assertSame('KazaKora', $linhas[$a->id]['loja']);

        // Soma dos pedidos = lucro da KazaKora no painel, menos a taxa que aqui é estimada.
        $painel = app(ContributionMargin::class)->porCanal(Carbon::parse('2026-11-20'), Carbon::parse('2026-11-21'))['loja'];
        $this->assertEqualsWithDelta($painel['lucro'] - 0.99, $linhas[$a->id]['lucro'] + $linhas[$b->id]['lucro'], 0.011);
    }

    public function test_taxa_estimada_pelo_meio_de_pagamento(): void
    {
        $this->assertEqualsWithDelta(0.99, LucroPorPedido::taxaEstimada(Payment::METHOD_PIX, 100), 0.001);
        $this->assertEqualsWithDelta(4.98, LucroPorPedido::taxaEstimada(Payment::METHOD_CARD, 100), 0.001);
        $this->assertEqualsWithDelta(3.49, LucroPorPedido::taxaEstimada(Payment::METHOD_BOLETO, 100), 0.001);
        $this->assertEqualsWithDelta(4.98, LucroPorPedido::taxaEstimada(null, 100), 0.001, 'sem meio: cartão');
    }

    public function test_pedido_aguardando_e_cancelado_ficam_sem_lucro(): void
    {
        $aguardando = $this->pedido(100, '2026-11-22 10:00:00', 40, extra: ['status' => Order::STATUS_AWAITING_PAYMENT]);
        $cancelado = $this->pedido(100, '2026-11-22 11:00:00', 40, extra: ['status' => Order::STATUS_CANCELLED]);
        $this->gasto('2026-11-22', 'meta_ads', 30);

        $linhas = collect(app(LucroPorPedido::class)->ultimos(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-26'), ['loja', 'ferrakora', 'beautykora']))->keyBy('id');

        $this->assertNull($linhas[$aguardando->id]['lucro']);
        $this->assertNull($linhas[$aguardando->id]['conta']);
        $this->assertSame('aguardando', $linhas[$aguardando->id]['situacao']);
        $this->assertSame('aguardando pagamento', $linhas[$aguardando->id]['sem_lucro']);
        $this->assertNull($linhas[$cancelado->id]['lucro']);
        $this->assertSame('cancelado', $linhas[$cancelado->id]['situacao']);
    }

    public function test_ultimos_traz_so_os_sites_os_mais_recentes_e_no_maximo_dez(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->pedido(50, sprintf('2026-11-%02d 10:00:00', $i), 10);
        }
        $ferra = $this->pedido(80, '2026-11-24 09:00:00', 10, extra: ['site_origem' => Order::SITE_FERRAKORA]);
        $shopee = $this->pedido(70, '2026-11-24 12:00:00', 10, extra: ['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP1']);

        $linhas = app(LucroPorPedido::class)->ultimos(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-26'), ['loja', 'ferrakora', 'beautykora']);

        $this->assertCount(10, $linhas);
        $this->assertSame($ferra->id, $linhas[0]['id']);
        $this->assertSame('FerraKora', $linhas[0]['loja']);
        $this->assertNotContains($shopee->id, array_column($linhas, 'id'));
    }

    public function test_card_aparece_so_no_painel_de_ecommerces_para_quem_ve_o_financeiro(): void
    {
        [$a, $b] = $this->cenario();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/painel/ecommerces/resultado?de=2026-11-01&ate=2026-11-25')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('ultimosPedidos.pedidos', 3)
                ->where('ultimosPedidos.pedidos.1.id', $b->id)
                ->where('ultimosPedidos.pedidos.0.lucro', null)
                ->where('ultimosPedidos.verTodos', '/admin/pedidos?grupo=ecommerces&de=2026-11-01&ate=2026-11-25'));

        // Filtro de loja da tela vale para o card.
        $this->actingAs($admin)->get('/admin/painel/ecommerces/resultado?de=2026-11-01&ate=2026-11-25&canal=ferrakora')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('ultimosPedidos.pedidos', 0)
                ->where('ultimosPedidos.verTodos', '/admin/pedidos?marketplace=ferrakora&de=2026-11-01&ate=2026-11-25'));

        // Marketplaces e outras abas não calculam o card.
        $this->actingAs($admin)->get('/admin/painel/marketplaces/resultado')
            ->assertOk()->assertInertia(fn ($page) => $page->missing('ultimosPedidos'));
        $this->actingAs($admin)->get('/admin/painel/ecommerces/resultado?aba=vendas')
            ->assertOk()->assertInertia(fn ($page) => $page->missing('ultimosPedidos'));

        // Sem financeiro.view a tela (e o lucro) não abre.
        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'financeiro.view')->update(['granted' => false]);
        $this->actingAs($assinante)->get('/admin/painel/ecommerces/resultado')->assertForbidden();
        $this->actingAs($assinante)->get('/admin/painel/ecommerces/indicadores')
            ->assertOk()->assertInertia(fn ($page) => $page->missing('ultimosPedidos'));

        // "Ver todos": a lista de pedidos filtrada pelos sites.
        $shopee = $this->pedido(70, '2026-11-20 12:00:00', 10, extra: ['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP1']);
        $this->actingAs($admin)->get('/admin/pedidos?grupo=ecommerces')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.grupo', 'ecommerces')
                ->where('orders.data', fn ($pedidos) => ! collect($pedidos)->pluck('id')->contains($shopee->id) && collect($pedidos)->pluck('id')->contains($a->id)));
    }
}
