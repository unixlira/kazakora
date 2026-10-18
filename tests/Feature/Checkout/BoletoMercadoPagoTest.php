<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\BoletoAguardandoPagamento;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Boleto pelo Mercado Pago no checkout da loja (pedido 2026-10-14). */
class BoletoMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    private const LINHA = '23793.38128 60000.026447 18062.308202 1 91510000031565';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.access_token' => 'token-de-teste',
            'services.mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'services.mercadopago.modo_teste' => false,
            'mercadopago.retry.times' => 1,
            'loja.boleto_dias_vencimento' => 3,
            'loja.boleto_cancelar_apos_dias' => 7,
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function respostaDoBoleto(int $id = 9001, string $status = 'pending'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'status_detail' => 'pending_waiting_payment',
            'payment_method_id' => 'bolbradesco',
            'date_of_expiration' => '2026-10-17T23:59:59.000-03:00',
            'barcode' => ['content' => '23791915100000315653381260000026441806230820'],
            'transaction_details' => [
                'external_resource_url' => 'https://www.mercadopago.com.br/payments/9001/ticket?caller_id=1',
                'digitable_line' => self::LINHA,
            ],
        ];
    }

    /** @return array{0: User, 1: Product, 2: ShippingMethod, 3: \App\Modules\Checkout\Models\Address} */
    private function cenario(): array
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'cliente@exemplo.com', 'name' => 'Maria da Silva']);
        $product = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $metodo = ShippingMethod::query()->first() ?? ShippingMethod::factory()->create();
        $address = $user->addresses()->create(['label' => 'Casa', 'recipient_name' => 'Maria', 'phone' => '11999990000', 'zip' => '01001-000', 'street' => 'Praça da Sé', 'number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'sp']);

        return [$user, $product, $metodo, $address];
    }

    public function test_cria_boleto_no_mercado_pago_com_endereco_cpf_e_vencimento_em_3_dias(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'America/Sao_Paulo'));
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response($this->respostaDoBoleto(), 201)]);
        [$user, $product, $metodo, $address] = $this->cenario();

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $resposta = $this->actingAs($user)->withSession(['checkout_draft' => ['shipping_method_id' => $metodo->id, 'address_id' => $address->id]])
            ->post('/finalizacao/pagamento', ['payment_method' => 'boleto', 'terms_accepted' => true]);

        $order = Order::firstOrFail();

        Http::assertSent(function (HttpRequest $request) use ($order, $user) {
            $payer = $request['payer'];

            return $request->method() === 'POST'
                && str_ends_with($request->url(), 'v1/payments')
                && $request['payment_method_id'] === 'bolbradesco'
                && $request['date_of_expiration'] === '2026-10-17T23:59:59.000-03:00'
                && $payer['email'] === "pedido-{$order->id}@kazakora.com"
                && $payer['first_name'] === 'Maria' && $payer['last_name'] === 'Da Silva'
                && $payer['identification'] === ['type' => 'CPF', 'number' => preg_replace('/\D/', '', $user->cpf)]
                && $payer['address'] === ['zip_code' => '01001000', 'street_name' => 'Praça da Sé', 'street_number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'federal_unit' => 'SP']
                && $request->hasHeader('X-Idempotency-Key', "order:{$order->id}:payment:1");
        });

        // Sem desconto do Pix.
        $this->assertSame(0.0, (float) $order->pix_discount_amount);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);

        $payment = $order->payments()->firstOrFail();
        $this->assertSame(Payment::METHOD_BOLETO, $payment->method_type);
        $this->assertSame('9001', $payment->mercadopago_payment_id);
        $this->assertSame(self::LINHA, $payment->boleto_linha_digitavel);
        $this->assertStringContainsString('/ticket', $payment->boleto_url);
        $this->assertSame('2026-10-17 23:59:59', $payment->expira_em->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));

        $resposta->assertInertia(fn ($page) => $page
            ->where('methodType', 'boleto')
            ->where('mercadoPagoBoleto.linhaDigitavel', self::LINHA)
            ->where('mercadoPagoBoleto.pdfUrl', 'https://www.mercadopago.com.br/payments/9001/ticket?caller_id=1'));

        Mail::assertSent(BoletoAguardandoPagamento::class, fn ($mail) => $mail->hasTo('cliente@exemplo.com')
            && $mail->linhaDigitavel === self::LINHA && $mail->order->is($order));

        // Pedido feito: o carrinho esvazia.
        $this->assertSame(0, app(\App\Modules\Cart\Support\CartManager::class)->items()->count());
    }

    public function test_boleto_nao_pode_ser_dividido_com_cartao(): void
    {
        Http::fake();
        [$user, $product, $metodo, $address] = $this->cenario();

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($user)->withSession(['checkout_draft' => ['shipping_method_id' => $metodo->id, 'address_id' => $address->id]])
            ->post('/finalizacao/pagamento', ['payment_method' => 'boleto', 'split' => true, 'payment_method_secondary' => 'card', 'split_percentage' => 50, 'terms_accepted' => true])
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_boleto_nao_entra_como_segunda_forma_do_split(): void
    {
        Http::fake();
        [$user, $product, $metodo, $address] = $this->cenario();

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($user)->withSession(['checkout_draft' => ['shipping_method_id' => $metodo->id, 'address_id' => $address->id]])
            ->post('/finalizacao/pagamento', ['payment_method' => 'card', 'split' => true, 'payment_method_secondary' => 'boleto', 'split_percentage' => 50, 'terms_accepted' => true])
            ->assertSessionHasErrors('payment_method_secondary');

        $this->assertSame(0, Order::count());
    }

    /** Pedido da loja com um boleto pendente, criado há $horas horas. */
    private function pedidoComBoleto(int $horas = 2, ?Carbon $venceEm = null): array
    {
        [$user, $product] = $this->cenario();
        $product->update(['stock' => 4]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '01001-000',
            'shipping_street' => 'Praça da Sé', 'shipping_number' => '1', 'shipping_neighborhood' => 'Sé',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        (new \App\Modules\Inventory\Models\StockMovement)->forceFill([
            'product_id' => $product->id, 'type' => \App\Modules\Inventory\Models\StockMovement::TYPE_SALE, 'stock_after' => 4,
            'quantity' => -1, 'reference_type' => $order->getMorphClass(), 'reference_id' => $order->id,
        ])->save();
        $order->forceFill(['created_at' => now()->subHours($horas)])->save();

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => '9001',
            'method_type' => Payment::METHOD_BOLETO,
            'amount' => 100,
            'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
            'boleto_linha_digitavel' => self::LINHA,
            'expira_em' => $venceEm ?? now()->addDays(3),
        ]);

        return [$order->fresh(), $payment, $product, $user];
    }

    public function test_varredura_de_30_min_nao_cancela_pedido_com_boleto_no_prazo(): void
    {
        [$order] = $this->pedidoComBoleto(horas: 5);

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_boleto_pago_dias_depois_confirma_o_pedido(): void
    {
        Queue::fake();
        Http::fake(['api.mercadopago.com/v1/payments/9001' => Http::response($this->respostaDoBoleto(status: 'approved'))]);
        [$order, $payment] = $this->pedidoComBoleto(horas: 50);

        $this->artisan('mercadopago:conferir-boletos')->assertSuccessful();

        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Queue::assertPushed(\App\Modules\Fiscal\Jobs\GenerateInvoiceJob::class);
    }

    public function test_boleto_ainda_pendente_no_prazo_nao_muda_nada(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/9001' => Http::response($this->respostaDoBoleto())]);
        [$order, $payment] = $this->pedidoComBoleto();

        $this->artisan('mercadopago:conferir-boletos')->assertSuccessful();

        $this->assertSame(Payment::STATUS_REQUIRES_CONFIRMATION, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_boleto_cancelado_pelo_mercado_pago_cancela_pedido_e_devolve_estoque(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/9001' => Http::response($this->respostaDoBoleto(status: 'cancelled'))]);
        [$order, $payment, $product] = $this->pedidoComBoleto(horas: 100, venceEm: now()->subDay());

        $this->artisan('mercadopago:conferir-boletos')->assertSuccessful();

        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_boleto_pendente_muito_depois_do_vencimento_e_cancelado_no_mercado_pago(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments/9001' => Http::sequence()
                ->push($this->respostaDoBoleto())
                ->push($this->respostaDoBoleto(status: 'cancelled')),
        ]);
        [$order, $payment] = $this->pedidoComBoleto(horas: 300, venceEm: now()->subDays(8));

        $this->artisan('mercadopago:conferir-boletos')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT' && $request['status'] === 'cancelled');
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_minhas_compras_mostra_pedido_com_boleto_aguardando(): void
    {
        [$order, , , $user] = $this->pedidoComBoleto();

        $this->actingAs($user)->get('/pedidos')
            ->assertInertia(fn ($page) => $page->where('orders.data.0.id', $order->id));

        $this->actingAs($user)->get("/pedidos/{$order->id}")
            ->assertInertia(fn ($page) => $page->where('pedido.pagamento.boleto.linha_digitavel', self::LINHA));
    }

    public function test_linha_digitavel_montada_do_codigo_de_barras(): void
    {
        $this->assertSame(self::LINHA, MercadoPagoPaymentService::linhaDigitavelDoCodigoDeBarras('23791915100000315653381260000026441806230820'));

        $resposta = $this->respostaDoBoleto();
        unset($resposta['transaction_details']['digitable_line']);
        $this->assertSame(self::LINHA, MercadoPagoPaymentService::dadosDoBoleto($resposta)['linhaDigitavel']);
    }
}
