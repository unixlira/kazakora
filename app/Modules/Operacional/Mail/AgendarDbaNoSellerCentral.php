<?php

namespace App\Modules\Operacional\Mail;

use Illuminate\Mail\Mailable;

/** Pedido Amazon DBA que a API não deixou agendar: agendar à mão no Seller Central (24/11/2026). */
class AgendarDbaNoSellerCentral extends Mailable
{
    public function __construct(
        public readonly string $pedidoAmazon,
        public readonly int $pedidoId,
        public readonly string $prazo,
        public readonly string $motivo,
        public readonly ?string $link,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject("⚠️ Agende no Seller Central: pedido Amazon {$this->pedidoAmazon} (prazo {$this->prazo})")
            ->view('emails.agendar-dba-seller-central');
    }
}
