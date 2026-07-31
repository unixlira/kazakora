<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class OrderConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order)
    {
        $this->order->loadMissing('items', 'invoice');
    }

    public function build(): self
    {
        return $this
            ->subject("Pedido #{$this->order->id} confirmado - Kazakora")
            ->view('emails.order-confirmation');
    }

    /**
     * Anexa o DANFE em PDF ao e-mail de confirmação quando a NF-e do pedido
     * já foi emitida (autorizada pela SEFAZ) e o PDF já foi gerado — ver
     * App\Modules\Fiscal\Services\InvoiceService.
     */
    public function attachments(): array
    {
        $invoice = $this->order->invoice;

        if (! $invoice?->danfe_path || ! Storage::disk('local')->exists($invoice->danfe_path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $invoice->danfe_path)
                ->as("nfe-pedido-{$this->order->id}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
