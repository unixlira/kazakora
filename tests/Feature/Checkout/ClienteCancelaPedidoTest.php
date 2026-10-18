<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
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
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Botão "Cancelar pedido" em Meus pedidos (2026-10-17): o cliente cancela
 * a própria compra do site antes de a etiqueta sair, recebe o e-mail e os
 * admins são avisados no sino. Ver CancelamentoDoPedido::cancelarPeloCliente().
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

    public function test_com_etiqueta_impressa_manda_falar_com_suporte(): void
    {
        Mail::fake();
        [$order, , $cliente] = $this->compra();
        PrintJob::create(['order_id' => $order->id, 'channel' => 'loja', 'label_path' => 'labels/y.pdf', 'status' => PrintJob::STATUS_PRINTED, 'printed_at' => now()]);

        $this->actingAs($cliente)
            ->get("/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pedido.cancelamento.pode', false)
                ->where('pedido.cancelamento.bloqueio', fn ($texto) => str_contains((string) $texto, 'suporte'))
                ->etc());

        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('error');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_pedido_enviado_nao_cancela(): void
    {
        [$order, , $cliente] = $this->compra(['status' => Order::STATUS_SHIPPED]);

        $this->actingAs($cliente)
            ->post("/pedidos/{$order->id}/cancelar", ['motivo' => 'Desisti da compra'])
            ->assertSessionHas('error');

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }
}
