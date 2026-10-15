<?php

namespace App\Modules\FerraKora\Mail;

use App\Modules\FerraKora\Models\FerraKoraCliente;
use Illuminate\Mail\Mailable;

/**
 * Link de nova senha da conta do site FerraKora (dados separados por projeto,
 * 2026-10-14). Síncrono como o da KazaKora: o cliente está esperando. Sem o
 * layout da KazaKora (logo e WhatsApp de lá).
 */
class NovaSenhaFerraKora extends Mailable
{
    public function __construct(
        public readonly FerraKoraCliente $cliente,
        public readonly string $link,
        public readonly int $minutos,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Criar nova senha - FerraKora')
            ->view('emails.ferrakora.nova-senha');
    }
}
