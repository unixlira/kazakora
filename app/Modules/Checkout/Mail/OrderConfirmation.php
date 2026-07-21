<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order)
    {
        $this->order->loadMissing('items');
    }

    public function build(): self
    {
        return $this
            ->subject("Pedido #{$this->order->id} confirmado - Kazakora")
            ->view('emails.order-confirmation');
    }
}
