<?php

namespace App\Modules\Contato\Mail;

use App\Modules\Contato\Models\MensagemContato;
use Illuminate\Mail\Mailable;

/**
 * Mensagem do formulário "Fale conosco" para o e-mail do dono da loja. O
 * "Responder" do e-mail já vai para quem escreveu.
 */
class MensagemDoSite extends Mailable
{
    public function __construct(public readonly MensagemContato $mensagem)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Mensagem do site: '.$this->mensagem->assunto)
            ->replyTo($this->mensagem->email, $this->mensagem->nome)
            ->view('emails.mensagem-do-site');
    }
}
