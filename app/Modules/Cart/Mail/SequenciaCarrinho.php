<?php

namespace App\Modules\Cart\Mail;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia;
use App\Support\MarcaDoEmail;
use App\Support\SiteAtual;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

/**
 * E-mail da sequência de carrinho abandonado (pedido do Lira 21/11/2026),
 * para quem não tem celular: o 1º é o lembrete do carrinho; o 2º, uma notícia
 * sobre o produto; o 4º, o último lembrete. O D+5 é o CadernoDoCarrinho.
 * SEM desconto, cupom ou preço. Marca da loja do carrinho (FerraKora com a
 * dela) e link de descadastro no rodapé.
 */
class SequenciaCarrinho extends Mailable
{
    public function __construct(
        public readonly CarrinhoAbandonadoSequencia $registro,
        public readonly int $numero,
        public readonly string $produto,
        public readonly string $link,
        public readonly ?string $noticia = null,
        public readonly ?string $imagem = null,
        // Texto de PromocaoDoCarrinho (22/11/2026): assunto e botão da oferta.
        public readonly bool $promocao = false,
    ) {
    }

    public static function assunto(int $numero, string $produto, string $nome): string
    {
        // Desde 26/11/2026 este e-mail só sai no lugar do WhatsApp de quem não tem celular (D+1, D+3, D+7).
        if ($numero === \App\Modules\Cart\Support\Sequencia\CalendarioSequencia::TOTAL) {
            return "Última lembrança: o {$produto} continua no seu carrinho";
        }

        return match (\App\Modules\Cart\Support\Sequencia\NoticiaDoProduto::ASSUNTOS[$numero] ?? null) {
            'dica' => "Uma dica sobre o {$produto}",
            'curiosidade' => "Você sabia? Uma curiosidade sobre o {$produto}",
            'cuidado' => "Como cuidar bem do {$produto}",
            'para_quem' => "Para quem é o {$produto}",
            'escolha' => "O que observar ao escolher o {$produto}",
            default => "{$nome}, você deixou o {$produto} no carrinho",
        };
    }

    public function build(): self
    {
        $ferrakora = $this->registro->site === SiteAtual::FERRAKORA;
        $marca = $ferrakora ? MarcaDoEmail::ferrakora() : MarcaDoEmail::kazakora();
        $nome = $this->registro->primeiroNome();

        return $this
            ->when($ferrakora, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject($this->promocao ? "Black Friday: a Lousa Mágica LED vai de graça com o {$this->produto} até 30/11" : self::assunto($this->numero, $this->produto, $nome))
            ->view('emails.carrinho-sequencia', [
                'marcaEmail' => $marca,
                'nome' => $nome,
                'produto' => $this->produto,
                'noticia' => $this->noticia,
                'imagem' => $this->imagem,
                'link' => $this->link,
                'lembrete' => $this->numero === 1,
                'promocao' => $this->promocao,
                'linkParar' => URL::signedRoute('carrinho.sequencia.parar', ['registro' => $this->registro->id]),
            ]);
    }
}
