<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * Notas fiscais da compra com brinde (pedido do Lira em 21/11/2026): a nota
 * de venda e a nota de bonificação do brinde, anexadas, no layout da loja.
 * Sem pedido de desculpas: o tom é de presente, não de correção.
 */
class NotaDoBrinde extends Mailable
{
    public function __construct(
        public readonly Order $order,
        public readonly Order $bonificacao,
    ) {
        $this->order->loadMissing('invoice', 'items');
        $this->bonificacao->loadMissing('invoice', 'items');
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::doPedido($this->order);

        return $this
            ->when($this->order->site_origem === Order::SITE_FERRAKORA, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject("Notas fiscais do seu pedido {$this->order->numero} - {$marca['nome']}")
            ->view('emails.nota-do-brinde', [
                'marcaEmail' => $marca,
                'brindes' => $this->bonificacao->items->pluck('product_name')->unique()->values(),
            ]);
    }

    public function attachments(): array
    {
        $anexos = [];
        foreach ([[$this->order, 'nfe-pedido'], [$this->bonificacao, 'nfe-brinde']] as [$pedido, $nome]) {
            $danfe = $pedido->invoice?->danfe_path;
            if ($danfe && Storage::disk('local')->exists($danfe)) {
                $anexos[] = Attachment::fromStorageDisk('local', $danfe)
                    ->as("{$nome}-{$this->order->id}.pdf")
                    ->withMime('application/pdf');
            }
        }

        return $anexos;
    }
}
