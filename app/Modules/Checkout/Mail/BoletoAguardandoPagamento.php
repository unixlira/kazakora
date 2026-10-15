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
 */
class BoletoAguardandoPagamento extends Mailable
{
    public function __construct(
        public readonly Order $order,
        public readonly float $valor,
        public readonly ?string $linhaDigitavel,
        public readonly ?string $pdfUrl,
        public readonly ?string $venceEm = null,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::doPedido($this->order);

        return $this
            // Remetente com o nome do site só no FerraKora; KazaKora segue o MAIL_FROM_NAME.
            ->when($this->order->site_origem === Order::SITE_FERRAKORA, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject("Seu boleto do pedido {$this->order->numero} está pronto")
            ->view('emails.boleto-aguardando-pagamento', [
                'marcaEmail' => $marca,
                'vencimento' => $this->venceEm ? Carbon::parse($this->venceEm)->timezone('America/Sao_Paulo') : null,
            ]);
    }
}
