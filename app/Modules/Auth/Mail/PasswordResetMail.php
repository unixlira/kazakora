<?php

namespace App\Modules\Auth\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

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
