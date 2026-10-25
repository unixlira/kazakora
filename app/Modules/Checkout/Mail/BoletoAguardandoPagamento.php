<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * "Seu boleto está pronto" (pedido 2026-10-14): mesmo esquema do Pix — o
 * Mercado Pago recebe um e-mail nosso como pagador e não escreve ao cliente;
 * quem manda a linha digitável e o link do boleto é a KazaKora.
 *
 * Desde 2026-10-25 (pedido do Lira) o próprio boleto em PDF vai anexado,
 * com o topo da loja (BoletoComMarca), para o cliente pagar sem abrir link.
 */
class BoletoAguardandoPagamento extends Mailable
{
    public function __construct(
        public readonly Order $order,
        public readonly float $valor,
        public readonly ?string $linhaDigitavel,
        public readonly ?string $pdfUrl,
        public readonly ?string $venceEm = null,
        public readonly ?string $pdf = null,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::doPedido($this->order);

        return $this
            // Remetente com o nome do site só no FerraKora; KazaKora segue o MAIL_FROM_NAME.
            ->when($this->order->site_origem === Order::SITE_FERRAKORA, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->when($this->pdf !== null, fn (self $mail) => $mail->attachData($this->pdf, "boleto-pedido-{$this->order->numero}.pdf", ['mime' => 'application/pdf']))
            ->subject("Seu boleto do pedido {$this->order->numero} está pronto")
            ->view('emails.boleto-aguardando-pagamento', [
                'marcaEmail' => $marca,
                'vencimento' => $this->venceEm ? Carbon::parse($this->venceEm)->timezone('America/Sao_Paulo') : null,
            ]);
    }
}
