<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Models\PrintJob;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Botão "Cancelar pedido" do admin (2026-10-17): motivo obrigatório,
 * estorno, estoque de volta e trava contra cancelar duas vezes. Sem e-mail
 * ao cliente (Lira, 2026-10-17). Ver CancelamentoDoPedido.
 */
class CancelarPedidoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: Product} */
    private function pedidoPago(array $overrides = []): array
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

        return [$order, $product];
    }

    private function pagamentoPix(Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => '777',
            'method_type' => Payment::METHOD_PIX,
            'amount' => 20,
            'status' => Payment::STATUS_CAPTURED,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_cancela_pedido_do_site_estorna_e_devolve_estoque_sem_mandar_email(): void
    {
        Mail::fake();
        [$order, $product] = $this->pedidoPago();
        $payment = $this->pagamentoPix($order);
        $this->mock(MercadoPagoPaymentService::class, fn ($mock) => $mock->shouldReceive('refundPayment')->once()->with('777')->andReturn([]));
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => 'Cliente pediu para cancelar'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame('Cliente pediu para cancelar', $order->motivo_cancelamento);
        $this->assertSame($admin->id, (int) $order->cancelado_por);
        $this->assertNotNull($order->cancelado_em);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stock);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('order_email_logs', ['order_id' => $order->id]);
    }

    public function test_segundo_clique_nao_estorna_nem_devolve_estoque_de_novo(): void
    {
        Mail::fake();
        [$order, $product] = $this->pedidoPago();
        $this->pagamentoPix($order);
        $this->mock(MercadoPagoPaymentService::class, fn ($mock) => $mock->shouldReceive('refundPayment')->once()->andReturn([]));
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => 'Sem estoque'])->assertSessionHas('success');
        $this->actingAs($admin)->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => 'Sem estoque'])->assertSessionHas('error');

        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_motivo_e_obrigatorio(): void
    {
        [$order] = $this->pedidoPago();

        $this->actingAs($this->admin())
            ->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_pedido_enviado_nao_devolve_estoque(): void
    {
        Mail::fake();
        [$order, $product] = $this->pedidoPago(['status' => Order::STATUS_SHIPPED]);

        $this->actingAs($this->admin())
            ->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => 'Extraviado'])
            ->assertSessionHas('success');

        $this->assertSame(8, (int) $product->fresh()->stock);
    }

    public function test_pedido_de_marketplace_nao_manda_email_e_previa_avisa_do_canal(): void
    {
        Mail::fake();
        [$order] = $this->pedidoPago(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => '2510170001']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get("/admin/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cancelamento.pode', true)
                ->where('cancelamento.canal', fn ($texto) => str_contains((string) $texto, 'Shopee'))
                ->etc());

        $this->actingAs($admin)
            ->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => 'Comprador desistiu'])
            ->assertSessionHas('success');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_previa_do_pedido_do_site_mostra_estorno_e_estoque(): void
    {
        [$order] = $this->pedidoPago();
        $this->pagamentoPix($order);

        $this->actingAs($this->admin())
            ->get("/admin/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cancelamento.pode', true)
                ->where('cancelamento.cliente', 'Nenhum e-mail sai para o cliente: ele vê o status novo em Meus pedidos.')
                ->where('cancelamento.canal', null)
                ->where('cancelamento.pagamentos.0', 'R$ 20,00 pagos: estorno total pedido ao Mercado Pago.')
                ->where('cancelamento.estoque', '2 unidades voltam para o estoque.')
                ->etc());
    }

    public function test_quem_so_ve_pedidos_nao_cancela(): void
    {
        [$order] = $this->pedidoPago();
        $cliente = User::factory()->create();

        $this->actingAs($cliente)
            ->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => 'Tentativa indevida'])
            ->assertForbidden();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_cancelar_tira_da_fila_a_etiqueta_que_ainda_nao_saiu(): void
    {
        [$order] = $this->pedidoPago();
        $naFila = PrintJob::create(['order_id' => $order->id, 'channel' => 'loja', 'label_path' => 'labels/x.pdf', 'status' => PrintJob::STATUS_QUEUED]);
        $impressa = PrintJob::create(['order_id' => $order->id, 'channel' => 'loja', 'label_path' => 'labels/y.pdf', 'status' => PrintJob::STATUS_PRINTED, 'printed_at' => now()]);

        $this->actingAs($this->admin())
            ->post("/admin/pedidos/{$order->id}/cancelar", ['motivo' => 'Pedido de teste'])
            ->assertSessionHas('success');

        $this->assertSame(PrintJob::STATUS_FAILED, $naFila->fresh()->status);
        $this->assertSame(PrintJob::MOTIVO_PEDIDO_CANCELADO, $naFila->fresh()->error_message);
        $this->assertSame(PrintJob::STATUS_PRINTED, $impressa->fresh()->status);
    }

    public function test_previa_avisa_quando_a_etiqueta_ja_foi_impressa(): void
    {
        [$order] = $this->pedidoPago();
        PrintJob::create(['order_id' => $order->id, 'channel' => 'loja', 'label_path' => 'labels/y.pdf', 'status' => PrintJob::STATUS_PRINTED, 'printed_at' => '2026-10-17 22:45:26']);

        $this->actingAs($this->admin())
            ->get("/admin/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cancelamento.etiqueta', fn ($texto) => str_contains((string) $texto, 'já foi impressa em 17/10 22:45'))
                ->etc());
    }
}
