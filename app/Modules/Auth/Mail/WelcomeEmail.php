<?php

namespace App\Modules\Auth\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

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
