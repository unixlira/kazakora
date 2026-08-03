<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $attributes = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create(array_merge([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
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
        ], $attributes));
    }

    public function test_issue_skips_emission_for_mercado_livre_orders_and_marks_invoice_as_external(): void
    {
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-99']);

        $invoice = app(InvoiceService::class)->issue($order);

        $this->assertSame(Invoice::STATUS_EXTERNAL, $invoice->status);
        $this->assertNull($invoice->chave_acesso);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'status' => Invoice::STATUS_EXTERNAL,
        ]);
    }

    public function test_issue_is_idempotent_for_mercado_livre_orders_and_does_not_duplicate_the_invoice_row(): void
    {
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-100']);

        $service = app(InvoiceService::class);
        $first = $service->issue($order);
        $second = $service->issue($order->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count());
    }
}
