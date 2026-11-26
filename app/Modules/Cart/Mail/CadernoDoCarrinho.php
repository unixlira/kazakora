<?php

namespace App\Modules\Cart\Mail;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia;
use App\Support\MarcaDoEmail;
use App\Support\SiteAtual;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

/**
 * E-mail do D+5 da sequência de carrinho abandonado (pedido do Lira
 * 26/11/2026): o Caderno KazaKora com UMA matéria do blog ligada ao produto
 * do carrinho e, embaixo, o produto com o link para voltar. Sem desconto,
 * sem preço. Rodapé e List-Unsubscribe com o descadastro de 1 clique.
 */
class CadernoDoCarrinho extends Mailable
{
    /** @param array{titulo: string, resumo: string, imagem: ?string, link: string, tipo: string}|null $materia */
    public function __construct(
        public readonly CarrinhoAbandonadoSequencia $registro,
        public readonly ?array $materia,
        public readonly string $produto,
        public readonly string $linkProduto,
        public readonly ?string $imagemProduto,
        public readonly string $linkSair,
    ) {
    }

    public static function nomeDoCaderno(string $site): string
    {
        return $site === SiteAtual::FERRAKORA ? 'Caderno FerraKora' : 'Caderno KazaKora';
    }

    public function build(): self
    {
        $ferrakora = $this->registro->site === SiteAtual::FERRAKORA;
        $marca = $ferrakora ? MarcaDoEmail::ferrakora() : MarcaDoEmail::kazakora();
        $caderno = self::nomeDoCaderno($this->registro->site);
        $assunto = $this->materia ? "{$caderno}: {$this->materia['titulo']}" : "{$caderno}: o {$this->produto} continua no seu carrinho";

        return $this
            ->from((string) (config('newsletter.remetente_email') ?: config('mail.from.address')), $marca['nome'] ?? 'KazaKora')
            ->subject(mb_strimwidth($assunto, 0, 120, '...'))
            ->view('emails.carrinho-caderno', [
                'marcaEmail' => $marca,
                'caderno' => $caderno,
                'nome' => $this->registro->primeiroNome(),
                'materia' => $this->materia,
                'produto' => $this->produto,
                'linkProduto' => $this->linkProduto,
                'imagemProduto' => $this->imagemProduto,
                'linkSair' => $this->linkSair,
            ]);
    }

    public function headers(): Headers
    {
        // One-Click (POST) só existe no descadastro da newsletter; o da sequência é um GET assinado.
        return new Headers(text: array_filter([
            'List-Unsubscribe' => '<'.$this->linkSair.'>',
            'List-Unsubscribe-Post' => str_contains($this->linkSair, '/newsletter/sair/') ? 'List-Unsubscribe=One-Click' : null,
        ]));
    }
}
