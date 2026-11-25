<?php

namespace App\Modules\Newsletter\Mail;

use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

/**
 * Resumo do blog por e-mail (pedido do Lira 24/11/2026): imagem, título,
 * resumo e link de cada matéria, e no rodapé o descadastro de 1 clique e a
 * identificação da loja. Também manda os cabeçalhos List-Unsubscribe
 * (Gmail/Yahoo mostram o botão "Cancelar inscrição" e exigem isso para envio
 * em massa).
 */
class BlogPorEmail extends Mailable
{
    /** @param array<int, array{titulo: string, resumo: string, imagem: ?string, link: string, categoria: string, tipo: string}> $itens */
    public function __construct(
        public readonly string $assunto,
        public readonly array $itens,
        public readonly string $linkSair,
        public readonly ?string $nomeDestinatario = null,
        public readonly bool $teste = false,
    ) {
    }

    public function build(): self
    {
        return $this
            ->from((string) (config('newsletter.remetente_email') ?: config('mail.from.address')), (string) config('newsletter.remetente_nome', 'KazaKora'))
            ->subject(($this->teste ? '[TESTE] ' : '').$this->assunto)
            ->view('emails.newsletter-blog', [
                'marcaEmail' => MarcaDoEmail::kazakora(),
                'assunto' => $this->assunto,
                'itens' => $this->itens,
                'nome' => $this->nomeDestinatario ? explode(' ', trim($this->nomeDestinatario))[0] : null,
                'linkSair' => $this->linkSair,
                'linkBlog' => \App\Modules\Newsletter\Support\ResumoDoBlog::comUtm(url('/blog')),
            ]);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->linkSair.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
