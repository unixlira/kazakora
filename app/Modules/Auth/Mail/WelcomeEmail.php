<?php

namespace App\Modules\Auth\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Enviada de forma síncrona (RegisteredUserController) — mesmo motivo do
 * PasswordResetMail: e-mail leve, sem dependência externa lenta, não faz
 * sentido esperar o cron da fila (até 1 minuto) pra isso.
 */
class WelcomeEmail extends Mailable
{
    use SerializesModels;

    public function __construct(public readonly User $user)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Bem-vindo à KazaKora!')
            ->view('emails.welcome');
    }
}
