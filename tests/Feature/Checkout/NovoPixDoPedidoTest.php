<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\SituacaoDoPagamento;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Novo Pix de 24 h para pedido do site cancelado por Pix vencido (pedido do
 * Lira 2026-10-23, pedidos 3375 e 3380): `pedidos:novo-pix`, varredura do
 * orders:expire-abandoned e página do rastreio sem login.
 */
class NovoPixDoPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.access_token' => 'token-de-teste',
            'services.mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'services.mercadopago.modo_teste' => false,
            'services.mercadopago.webhook_secret' => null,
            'mercadopago.retry.times' => 1,
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        Queue::fake();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-23 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Pedido do site como o orders:expire-abandoned deixa: vendido (estoque
     * baixado), Pix antigo cancelado, pedido cancelado e estoque devolvido.
     *
     * @return array{0: Order, 1: Product}
     */
    private function pedidoCanceladoPorPix(int $estoque = 5, array $extra = [], string $cpf = '12345678909'): array
    {
        $product = Product::factory()->create(['price' => 548.02, 'stock' => $estoque, 'is_active' => true]);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Ademir Teste', 'cpf' => $cpf]);

        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_STORE,
            'external_order_id' => '261023P6RK58W7',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_name' => 'Ademir Teste', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 576.86, 'shipping_cost' => 0, 'pix_discount_amount' => 28.84, 'discount_amount' => 28.84, 'total' => 548.02,
        ], $extra));
        $order->forceFill(['created_at' => now()->subHours(8)])->save();
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => 'Bike Spinning', 'product_price' => 576.86,
            'quantity' => 1, 'subtotal' => 576.86, 'item_type' => OrderItem::TYPE_PRODUCT,
        ]);

        $stock = app(StockManager::class);
        $stock->adjust($product, -1, StockMovement::TYPE_SALE, reason: "Pedido #{$order->id}", reference: $order);

        Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => $cpf === '12345678909' ? '1001' : '1001-'.$cpf,
            'method_type' => Payment::METHOD_PIX,
            'amount' => 548.02,
            'status' => Payment::STATUS_CANCELED,
        ]);

        $order->forceFill([
            'status' => Order::STATUS_CANCELLED,
            'cancelado_em' => now()->subHours(7),
            'motivo_cancelamento' => SituacaoDoPagamento::MOTIVO_PIX_EXPIRADO,
        ])->save();
        app(\App\Modules\Checkout\Support\OrderPaymentFinalizer::class)->restoreStockIfNeeded($order, 'Pedido abandonado');

        return [$order->fresh(), $product->fresh()];
    }

    /** Status do Pix novo no Mercado Pago (GET /v1/payments/2002). */
    private string $statusNoMp = 'pending';

    private function fakeMercadoPago(): void
    {
        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/payments')) {
                return Http::response([
                    'id' => 2002,
                    'status' => 'pending',
                    'date_of_expiration' => now()->addHours(24)->format('Y-m-d\TH:i:s.vP'),
                    'point_of_interaction' => ['transaction_data' => [
                        'qr_code' => '00020126580014br.gov.bcb.pix0136pix-de-teste5204000053039865406548.02',
                        'qr_code_base64' => 'iVBORw0KGgo=',
                    ]],
                ], 201);
            }

            if (str_ends_with($request->url(), '/v1/payments/2002')) {
                if ($request->method() === 'PUT' && $this->statusNoMp === 'pending') {
                    $this->statusNoMp = 'cancelled';
                }

                return Http::response(['id' => 2002, 'status' => $this->statusNoMp]);
            }

            return Http::response(['message' => 'inesperado'], 404);
        });
    }

    private function saldoDoPedido(Order $order, Product $product): int
    {
        return (int) StockMovement::query()->where('product_id', $product->id)
            ->where('reference_type', $order->getMorphClass())->where('reference_id', $order->id)->sum('quantity');
    }

    public function test_reabre_o_pedido_reserva_estoque_e_gera_pix_de_24_horas(): void
    {
        $this->fakeMercadoPago();
        [$order, $product] = $this->pedidoCanceladoPorPix(estoque: 5);
        $this->assertSame(5, $product->stock);

        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])
            ->expectsOutputToContain('reaberto, estoque reservado e Pix novo gerado')
            ->expectsOutputToContain('Ref do rastreio: '.$order->trackingRef())
            ->expectsOutputToContain('/rastreio/'.$order->trackingRef())
            ->expectsOutputToContain('24/10/2026 15:00')
            ->assertSuccessful();

        $order->refresh();
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertSame('261023P6RK58W7', $order->external_order_id);
        $this->assertSame('548.02', (string) $order->total);
        $this->assertNull($order->cancelado_em);
        $this->assertNull($order->motivo_cancelamento);
        $this->assertNull($order->stock_restored_at);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(-1, $this->saldoDoPedido($order, $product));

        $novo = $order->payments()->whereNull('substituido_em')->sole();
        $this->assertSame('2002', $novo->mercadopago_payment_id);
        $this->assertSame(Payment::STATUS_REQUIRES_CONFIRMATION, $novo->status);
        $this->assertSame('548.02', (string) $novo->amount);
        $this->assertNotNull($novo->pix_copia_e_cola);
        $this->assertTrue($novo->expira_em->equalTo(now()->addHours(24)));
        $this->assertNotNull(Payment::query()->where('mercadopago_payment_id', '1001')->value('substituido_em'));

        Http::assertSent(function (HttpRequest $request) use ($order) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/v1/payments')) {
                return false;
            }

            $vence = Carbon::parse($request['date_of_expiration']);

            return $request['transaction_amount'] === 548.02
                && $request['payment_method_id'] === 'pix'
                && abs($vence->diffInMinutes(now()->addHours(24))) < 1
                && $request->hasHeader('X-Idempotency-Key')
                && $request['payer']['email'] === "pedido-{$order->id}@kazakora.com";
        });
    }

    public function test_dry_run_nao_grava_nem_chama_o_mercado_pago(): void
    {
        Http::fake();
        [$order, $product] = $this->pedidoCanceladoPorPix();

        $this->artisan('pedidos:novo-pix', ['pedido' => ['261023P6RK58W7'], '--dry-run' => true])
            ->expectsOutputToContain('pode reabrir (estoque ok)')
            ->expectsOutputToContain('/rastreio/'.$order->trackingRef())
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_sem_estoque_para_e_avisa(): void
    {
        Http::fake();
        [$order, $product] = $this->pedidoCanceladoPorPix();
        $product->forceFill(['stock' => 0])->save();

        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])
            ->expectsOutputToContain('sem estoque para reabrir')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_recusa_pedido_pago_e_de_marketplace(): void
    {
        Http::fake();
        [$pago] = $this->pedidoCanceladoPorPix();
        $pago->forceFill(['status' => Order::STATUS_PAID, 'motivo_cancelamento' => null])->save();
        $pago->payments()->update(['status' => Payment::STATUS_CAPTURED]);

        [$shopee] = $this->pedidoCanceladoPorPix(extra: ['external_order_id' => 'SHP123'], cpf: '98765432100');
        $shopee->forceFill(['origin' => 'shopee'])->save();

        $this->artisan('pedidos:novo-pix', ['pedido' => [$pago->id, $shopee->id]])
            ->expectsOutputToContain('pagamento aprovado')
            ->expectsOutputToContain('marketplace')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_PAID, $pago->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $shopee->fresh()->status);
    }

    public function test_expire_abandoned_respeita_o_vencimento_e_depois_cancela_devolvendo_uma_vez(): void
    {
        $this->fakeMercadoPago();
        [$order, $product] = $this->pedidoCanceladoPorPix(estoque: 5);
        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])->assertSuccessful();
        $this->assertSame(4, $product->fresh()->stock);

        // Pedido criado há 8 h, mas o Pix novo ainda vale: não cancela.
        $this->artisan('orders:expire-abandoned')->assertSuccessful();
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock);

        // Venceu há 5 min (dentro da folga): ainda não.
        Carbon::setTestNow(now()->addHours(24)->addMinutes(5));
        $this->artisan('orders:expire-abandoned')->assertSuccessful();
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);

        // Depois da folga: o Mercado Pago dá o Pix como cancelado e o pedido cai.
        Carbon::setTestNow(now()->addMinutes(20));
        $this->artisan('orders:expire-abandoned')->assertSuccessful();
        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(SituacaoDoPagamento::MOTIVO_PIX_EXPIRADO, $order->motivo_cancelamento);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(0, $this->saldoDoPedido($order, $product));
    }

    public function test_pix_novo_pago_confirma_o_pedido_pela_varredura(): void
    {
        $this->fakeMercadoPago();
        [$order] = $this->pedidoCanceladoPorPix();
        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])->assertSuccessful();

        $this->statusNoMp = 'approved';
        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNull($order->stock_restored_at);
        Queue::assertPushed(\App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob::class);
    }

    public function test_rastreio_mostra_o_pix_sem_login_e_some_depois_de_pago(): void
    {
        $this->fakeMercadoPago();
        [$order] = $this->pedidoCanceladoPorPix();
        $link = '/rastreio/'.$order->trackingRef();

        // Cancelado, sem Pix aberto: visitante continua indo para o login.
        $this->get($link)->assertRedirect(route('entrar'));

        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])->assertSuccessful();

        $this->get($link)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pedido.publico', true)
            ->where('pedido.pix.valor', 548.02)
            ->where('pedido.pix.copia_e_cola', '00020126580014br.gov.bcb.pix0136pix-de-teste5204000053039865406548.02')
            ->where('pedido.pix.qr_base64', 'iVBORw0KGgo=')
            ->where('pedido.cidade', null)
            ->where('pedido.etapas', [])
            ->has('pedido.pix.vencimento'));

        // Dono logado vê o pedido completo e o Pix.
        $this->actingAs($order->user)->get($link)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pedido.publico', false)
            ->where('pedido.cidade', 'São Paulo/SP')
            ->has('pedido.pix.copia_e_cola'));
        auth()->logout();

        $this->getJson($link.'/pix')->assertOk()->assertJson(['pago' => false, 'aberto' => true]);

        $this->statusNoMp = 'approved';
        $this->getJson($link.'/pix')->assertOk()->assertJson(['pago' => true, 'aberto' => false]);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        // Pago: o link volta a pedir login (rastreio completo só do dono).
        $this->get($link)->assertRedirect(route('entrar'));
    }

    public function test_pix_vencido_some_da_pagina(): void
    {
        $this->fakeMercadoPago();
        [$order] = $this->pedidoCanceladoPorPix();
        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])->assertSuccessful();

        Carbon::setTestNow(now()->addHours(25));

        $this->get('/rastreio/'.$order->trackingRef())->assertRedirect(route('entrar'));
        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('pedido.pix', null));
    }

    public function test_rodar_de_novo_com_pix_valido_nao_gera_outro(): void
    {
        $this->fakeMercadoPago();
        [$order, $product] = $this->pedidoCanceladoPorPix();
        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])->assertSuccessful();

        $this->artisan('pedidos:novo-pix', ['pedido' => [$order->id]])
            ->expectsOutputToContain('já tem Pix válido')
            ->assertSuccessful();

        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(4, $product->fresh()->stock);
    }
}
