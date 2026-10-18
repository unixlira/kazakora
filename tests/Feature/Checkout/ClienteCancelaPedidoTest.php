<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\ProcessarCancelamentoDoPedidoJob;
use App\Modules\Checkout\Mail\PedidoCancelado;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Models\PrintJob;
use App\Notifications\PedidoCanceladoPeloClienteNotification;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Botão "Cancelar pedido" em Meus pedidos (2026-10-17): o cliente cancela
 * a própria compra do site na hora (desde 2026-10-18 até sair para entrega,
 * sem passar pelo suporte), recebe o e-mail e os admins são avisados no sino. Ver
 * CancelamentoDoPedido::solicitarPeloCliente() e ProcessarCancelamentoDoPedidoJob
 * (fila, 2026-10-18).
 */
class ClienteCancelaPedidoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: Product, 2: User} */
    private function compra(array $overrides = []): array
    {
        $product = Product::factory()->create(['stock' => 10]);
        $cliente = User::factory()->create(['email' => 'cliente@example.com']);

        $order = Order::create(array_merge([
            'user_id' => $cliente->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 20,
            'total' => 20,
        ], $overrides));

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 10,
            'quantity' => 2,
            'subtotal' => 20,
        ]);

        app(StockManager::class)->adjust($product, -2, StockMovement::TYPE_SALE, reference: $order);

        return [$order, $product, $cliente];
    }

    public function test_cliente_cancela_estorna_devolve_estoque_manda_email_e_avisa_admin(): void
    {
        Mail::fake();
        Notification::fake();
        [$order, $product, $cliente] = $this->compra();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => '777',
            'method_type' => Payment::METHOD_PIX,
            'amount' => 20,
            'status' => Payment::STATUS_CAPTURED,
        ]);
        $this->mock(MercadoPagoPaymentService::class, fn ($mock) => $mock->shouldReceive('refundPayment')->once()->with('777')->andReturn([]));

        $this->actingAs($cliente)
            ->get("/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.cancelamento.pode', true)
                ->where('pedido.cancelamento.estorno', true)
                ->etc());

        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Comprei errado'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame('Cancelado pelo cliente: Comprei errado', $order->motivo_cancelamento);
        $this->assertSame($cliente->id, (int) $order->cancelado_por);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stock);

        Mail::assertSent(PedidoCancelado::class, fn (PedidoCancelado $mail) => $mail->hasTo('cliente@example.com') && $mail->valorEstornado === 20.0);
        Notification::assertSentTo($admin, PedidoCanceladoPeloClienteNotification::class);
    }

    public function test_enviar_so_registra_e_o_resto_vai_para_a_fila(): void
    {
        Queue::fake();
        Mail::fake();
        [$order, $product, $cliente] = $this->compra();
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => '778',
            'method_type' => Payment::METHOD_PIX,
            'amount' => 20,
            'status' => Payment::STATUS_CAPTURED,
        ]);
        $etiqueta = PrintJob::create(['order_id' => $order->id, 'channel' => 'loja', 'label_path' => 'labels/z.pdf', 'status' => PrintJob::STATUS_QUEUED]);
        $this->mock(MercadoPagoPaymentService::class, fn ($mock) => $mock->shouldNotReceive('refundPayment'));

        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra: achei caro'])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($texto) => str_contains($texto, 'sendo processado'));

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('Cancelado pelo cliente: Desisti da compra: achei caro', $order->fresh()->motivo_cancelamento);
        $this->assertSame(PrintJob::STATUS_FAILED, $etiqueta->fresh()->status);
        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
        $this->assertSame(8, (int) $product->fresh()->stock);
        Mail::assertNothingSent();
        Queue::assertPushedOn('nfe', ProcessarCancelamentoDoPedidoJob::class, fn ($job) => $job->orderId === $order->id
            && $job->statusAnterior === Order::STATUS_PAID
            && str_contains(implode(' ', $job->avisosDoPacote), 'fila da impressora'));

        // Segundo clique: nada de novo na fila.
        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('error');
        Queue::assertPushed(ProcessarCancelamentoDoPedidoJob::class, 1);
    }

    public function test_falha_no_job_avisa_os_admins(): void
    {
        Notification::fake();
        [$order] = $this->compra(['status' => Order::STATUS_CANCELLED]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        (new ProcessarCancelamentoDoPedidoJob($order->id, Order::STATUS_PAID))->failed(new \RuntimeException('SEFAZ fora do ar'));

        Notification::assertSentTo($admin, PedidoCanceladoPeloClienteNotification::class,
            fn (PedidoCanceladoPeloClienteNotification $aviso) => str_contains($aviso->toArray($admin)['body'], 'SEFAZ fora do ar'));
    }

    public function test_motivo_e_obrigatorio(): void
    {
        [$order, , $cliente] = $this->compra();

        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_outro_cliente_nao_cancela(): void
    {
        [$order] = $this->compra();

        $this->actingAs(User::factory()->create())
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertForbidden();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_com_etiqueta_impressa_cancela_na_hora_e_avisa_admin_do_pacote(): void
    {
        Mail::fake();
        Notification::fake();
        [$order, , $cliente] = $this->compra();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        PrintJob::create(['order_id' => $order->id, 'channel' => 'loja', 'label_path' => 'labels/y.pdf', 'status' => PrintJob::STATUS_PRINTED, 'printed_at' => now()]);

        $this->actingAs($cliente)
            ->get("/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.cancelamento.pode', true)
                ->etc());

        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('success');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        Mail::assertSent(PedidoCancelado::class);
        Notification::assertSentTo($admin, PedidoCanceladoPeloClienteNotification::class,
            fn (PedidoCanceladoPeloClienteNotification $aviso) => str_contains($aviso->toArray($admin)['body'], 'tire o pacote da bancada'));
    }

    public function test_pedido_enviado_nao_cancela(): void
    {
        [$order, , $cliente] = $this->compra(['status' => Order::STATUS_SHIPPED]);

        $this->actingAs($cliente)
            ->get("/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.cancelamento.pode', false)
                ->etc());

        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('error');

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }
}
