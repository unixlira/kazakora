<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * Não é ShouldQueue: sempre enviada de dentro de
 * App\Modules\Checkout\Jobs\SendOrderReceiptEmailJob, que já é o job na
 * fila — enviar aqui de novo criaria um segundo job invisível, escondendo o
 * resultado real do try/catch de retry/log do job.
 */
class OrderConfirmation extends Mailable
{

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
