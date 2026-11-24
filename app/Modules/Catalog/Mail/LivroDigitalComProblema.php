<?php

namespace App\Modules\Catalog\Mail;

use Illuminate\Mail\Mailable;

/**
 * Aviso ao Lira de livro de brinde (Promoção PDFs) que não chega inteiro ao
 * cliente (24/11/2026): PDF sumido do disco ou vazio. Sai do e-mail de
 * confirmação do pedido (uma vez por livro por dia) e do livros:conferir
 * (uma vez até resolver).
 */
class LivroDigitalComProblema extends Mailable
{
    /**
     * @param  list<array{id: int, titulo: string, problema: string, produtos?: list<string>}>  $problemas
     */
    public function __construct(
        public readonly array $problemas,
        public readonly string $origem,
    ) {
    }

    public function build(): self
    {
        $qtd = count($this->problemas);

        return $this
            ->subject($qtd === 1
                ? "⚠️ Livro digital com problema no PDF: {$this->problemas[0]['titulo']}"
                : "⚠️ {$qtd} livros digitais com problema no PDF")
            ->view('emails.livro-digital-problema', [
                'linkAdmin' => rescue(fn () => route('admin.entregaveis.livros.listar'), null, false),
            ]);
    }
}
