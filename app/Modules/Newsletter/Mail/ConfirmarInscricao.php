<?php

namespace App\Modules\Newsletter\Mail;

use App\Modules\Newsletter\Models\NewsletterInscrito;
use App\Support\MarcaDoEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

/**
 * Duplo opt-in da newsletter (pedido do Lira 24/11/2026): o inscrito só
 * recebe o resumo do blog depois de clicar em "Confirmar inscrição".
 */
class ConfirmarInscricao extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly NewsletterInscrito $inscrito)
    {
    }

    public function build(): self
    {
        return $this
            ->from((string) (config('newsletter.remetente_email') ?: config('mail.from.address')), (string) config('newsletter.remetente_nome', 'KazaKora'))
            ->subject('Confirme sua inscrição na newsletter da KazaKora')
            ->view('emails.newsletter-confirmar', [
                'marcaEmail' => MarcaDoEmail::kazakora(),
                'nome' => $this->inscrito->nome ? explode(' ', trim($this->inscrito->nome))[0] : null,
                'linkConfirmar' => route('newsletter.confirmar', $this->inscrito->token),
                'linkSair' => route('newsletter.sair', $this->inscrito->token),
            ]);
    }
}
