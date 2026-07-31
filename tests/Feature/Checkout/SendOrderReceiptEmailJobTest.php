<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderReceiptEmailJob;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderEmailLog;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SendOrderReceiptEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
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

    public function test_sends_with_the_danfe_attached_when_the_invoice_is_authorized(): void
    {
        Storage::fake('local');
        Mail::fake();

        $order = $this->makeOrder();
        $danfePath = "invoices/{$order->id}/danfe-test.pdf";
        Storage::disk('local')->put($danfePath, '%PDF-1.3 fake');
        Invoice::create(['order_id' => $order->id, 'status' => Invoice::STATUS_AUTHORIZED, 'numero' => 1, 'danfe_path' => $danfePath]);

        SendOrderReceiptEmailJob::dispatchSync($order->id);

        Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $mail) => count($mail->attachments()) === 1);
        $this->assertDatabaseHas('order_email_logs', [
            'order_id' => $order->id,
            'status' => OrderEmailLog::STATUS_SENT,
            'invoice_attached' => true,
        ]);
    }

    public function test_sends_without_attachment_when_there_is_no_authorized_invoice_yet(): void
    {
        Mail::fake();

        $order = $this->makeOrder();

        SendOrderReceiptEmailJob::dispatchSync($order->id);

        Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $mail) => count($mail->attachments()) === 0);
        $this->assertDatabaseHas('order_email_logs', [
            'order_id' => $order->id,
            'status' => OrderEmailLog::STATUS_SENT,
            'invoice_attached' => false,
        ]);
    }

    public function test_is_idempotent_and_does_not_resend_once_already_logged_as_sent(): void
    {
        Mail::fake();

        $order = $this->makeOrder();
        OrderEmailLog::create([
            'order_id' => $order->id,
            'mailable' => 'order_confirmation',
            'attempt' => 1,
            'status' => OrderEmailLog::STATUS_SENT,
            'invoice_attached' => false,
        ]);

        SendOrderReceiptEmailJob::dispatchSync($order->id);

        Mail::assertNothingSent();
        $this->assertSame(1, OrderEmailLog::where('order_id', $order->id)->count());
    }
}
