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

    /**
     * $senhaTemporaria só vem pra conta criada no checkout sem cadastro
     * (pedido 2026-10-10) — o cliente entra com o e-mail e essa senha.
     */
    public function __construct(public readonly User $user, public readonly ?string $senhaTemporaria = null)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Bem-vindo à KazaKora!')
            ->view('emails.welcome');
    }
}
