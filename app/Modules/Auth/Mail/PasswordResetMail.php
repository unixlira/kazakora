<?php

namespace App\Modules\Auth\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Enviada de forma síncrona (User::sendPasswordResetNotification) — o
 * cliente está esperando esse e-mail na hora (fluxo de senha), então não
 * pode ficar sujeito ao intervalo de 1 minuto do cron da fila.
 */
class PasswordResetMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $resetUrl,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Redefinir sua senha - KazaKora')
            ->view('emails.password-reset', [
                'user' => $this->user,
                'resetUrl' => $this->resetUrl,
                'expireMinutes' => config('auth.passwords.users.expire'),
            ]);
    }
}
