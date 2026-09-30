<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Notifications\CancelledOrderWithAuthorizedInvoiceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29: o canal cancelava a venda depois de a NF-e já estar
 * autorizada e ninguém ficava sabendo — a nota seguia valendo (e contando
 * no faturamento) até passar o prazo de 24h de cancelamento na SEFAZ.
 * Cancelar sozinho é irreversível, então o sistema só avisa os admins.
 */
class CancelledOrderWithAuthorizedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_SHOPEE,
            'external_order_id' => 'SH-'.uniqid(),
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_cancelling_an_order_with_authorized_invoice_notifies_admins_without_cancelling_the_invoice(): void
    {
        Queue::fake();
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->paidOrder();
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'status' => Invoice::STATUS_AUTHORIZED,
            'serie' => 2,
            'numero' => 4321,
            'autorizada_em' => now()->subHours(3),
        ]);

        app(OrderImportService::class)->syncStatus($order, Order::STATUS_CANCELLED);

        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $invoice->refresh()->status, 'Cancelar na SEFAZ é decisão do usuário, nunca automática.');

        Notification::assertSentTo($admin, CancelledOrderWithAuthorizedInvoiceNotification::class, function ($notification) use ($admin, $order) {
            $message = $notification->toArray($admin)['message'].' '.$notification->toArray($admin)['body'];

            return str_contains($message, '4321')
                && str_contains($message, "#{$order->id}")
                && str_contains($message, '24h');
        });
    }

    public function test_cancelling_an_order_without_authorized_invoice_does_not_notify(): void
    {
        Queue::fake();
        Notification::fake();
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->paidOrder();

        app(OrderImportService::class)->syncStatus($order, Order::STATUS_CANCELLED);

        Notification::assertNothingSent();
    }

    /**
     * Carrinho do ML: a nota está no titular e cobre também os pedidos que
     * continuam vendidos — o aviso não pode mandar cancelar a nota.
     */
    public function test_cancelling_a_secondary_pack_order_warns_that_the_pack_invoice_must_not_be_cancelled(): void
    {
        Queue::fake();
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $titular = $this->paidOrder();
        $titular->update(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'channel_pack_id' => 'PACK-1']);
        $secundario = $this->paidOrder();
        $secundario->update(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'channel_pack_id' => 'PACK-1']);
        $invoice = Invoice::create(['order_id' => $titular->id, 'status' => Invoice::STATUS_AUTHORIZED, 'serie' => 2, 'numero' => 555, 'valor_total' => 200]);

        app(OrderImportService::class)->syncStatus($secundario, Order::STATUS_CANCELLED);

        $this->assertSame(Invoice::STATUS_AUTHORIZED, $invoice->refresh()->status);
        Notification::assertSentTo($admin, CancelledOrderWithAuthorizedInvoiceNotification::class, function ($notification) use ($admin, $titular) {
            $body = $notification->toArray($admin)['body'];

            return str_contains($body, 'carrinho inteiro') && str_contains($body, "#{$titular->id}") && ! str_contains($body, '24h');
        });
    }
}
