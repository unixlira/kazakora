<?php

namespace App\Modules\Catalog\Mail;

use App\Modules\Catalog\Support\SolicitacaoAvaliacao;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

/**
 * "Avalie sua compra" (pedido do Lira 2026-10-18): sai alguns dias depois da
 * entrega do pedido do site, com um botão por produto (leva à página do
 * produto com a janela de avaliação aberta) e o cupom do cliente para a
 * próxima compra. Texto editado na tela de Avaliações do admin.
 */
class PedidoAvaliacao extends Mailable
{
    /**
     * @param  array  $produtos  [['id', 'nome', 'imagem', 'link']] de SolicitacaoAvaliacao::produtosParaAvaliar()
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $produtos,
        public readonly ?Coupon $coupon,
        public readonly array $config,
    ) {
    }

    public function build(): self
    {
        $valores = SolicitacaoAvaliacao::valores($this->order, $this->coupon, $this->config, $this->produtos);
        $preencher = fn (string $texto) => SolicitacaoAvaliacao::preencher($texto, $valores);

        return $this
            ->subject($preencher($this->config['assunto']))
            ->view('emails.pedido-avaliacao', [
                'titulo' => $preencher($this->config['titulo']),
                'mensagem' => $preencher($this->config['mensagem']),
                'botao' => $preencher($this->config['botao']),
                'linkLoja' => $this->coupon
                    ? route('catalogo.inicio', ['cupom' => $this->coupon->code])
                    : route('catalogo.inicio'),
                'linkSair' => $this->order->user?->id
                    ? URL::signedRoute('promocoes.sair', ['user' => $this->order->user->id])
                    : null,
            ]);
    }
}
