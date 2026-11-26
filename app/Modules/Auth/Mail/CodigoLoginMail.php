<?php

namespace App\Modules\Auth\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;

/**
 * Código de 6 dígitos do login do admin (CodigoLoginAdmin). Enviado na hora,
 * sem fila: a pessoa está parada na tela esperando o código.
 */
class CodigoLoginMail extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $codigo,
        public readonly int $validadeMinutos,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Seu código de acesso ao painel - KazaKora')
            ->view('emails.codigo-login', [
                'user' => $this->user,
                'codigo' => $this->codigo,
                'validadeMinutos' => $this->validadeMinutos,
            ]);
    }
}
