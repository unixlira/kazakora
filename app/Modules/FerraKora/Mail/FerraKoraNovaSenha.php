<?php

namespace App\Modules\FerraKora\Mail;

use App\Http\Controllers\Api\FerraKoraContaController;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use Illuminate\Mail\Mailable;

/**
 * Link de nova senha do site FerraKora (2026-10-15). Com o nome e a cor do
 * FerraKora, sem nada da KazaKora; enviado na hora (o cliente está esperando).
 */
class FerraKoraNovaSenha extends Mailable
{
    public function __construct(
        public readonly FerraKoraCliente $cliente,
        public readonly string $link,
    ) {
    }

    public function build(): self
    {
        return $this
            ->from((string) config('mail.from.address'), 'FerraKora')
            ->subject('Criar nova senha - FerraKora')
            ->view('emails.ferrakora.nova-senha', [
                'cliente' => $this->cliente,
                'link' => $this->link,
                'minutos' => FerraKoraContaController::MINUTOS_DO_LINK,
            ]);
    }
}
