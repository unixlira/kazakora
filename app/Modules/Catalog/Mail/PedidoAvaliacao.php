<?php

namespace App\Modules\Catalog\Mail;

use App\Modules\Catalog\Support\SolicitacaoAvaliacao;
use App\Modules\Checkout\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

/**
 * "Avalie sua compra" (pedido do Lira 2026-10-18): sai alguns dias depois da
 * entrega do pedido do site. Todo botão e toda estrela levam à página própria
 * de avaliação do pedido (não à página do produto); a estrela clicada já vem
 * marcada. O cupom não vai no e-mail: nasce na página, pelo desconto da nota.
 * Texto editado na tela de Avaliações do admin.
 */
class PedidoAvaliacao extends Mailable
{
    /**
     * @param  array  $produtos  [['id', 'nome', 'imagem']] de SolicitacaoAvaliacao::produtosParaAvaliar()
     * @param  string  $link  página de avaliação do pedido (AvaliacaoSolicitacao::link())
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $produtos,
        public readonly string $link,
        public readonly array $config,
    ) {
    }

    public function build(): self
    {
        $valores = SolicitacaoAvaliacao::valores($this->order, $this->config, $this->produtos);
        $preencher = fn (string $texto) => SolicitacaoAvaliacao::preencher($texto, $valores);

        return $this
            ->subject($preencher($this->config['assunto']))
            ->view('emails.pedido-avaliacao', [
                'titulo' => $preencher($this->config['titulo']),
                'mensagem' => $preencher($this->config['mensagem']),
                'botao' => $preencher($this->config['botao']),
                'desconto' => SolicitacaoAvaliacao::descricaoDesconto($this->config),
                'tabela' => $this->config['cupom_ativo'] ? $this->config['cupom_por_estrela'] : [],
                'linkNota' => fn (int $produtoId, int $nota) => $this->link.'?'.http_build_query(['produto' => $produtoId, 'nota' => $nota]),
                'linkSair' => $this->order->user?->id
                    ? URL::signedRoute('promocoes.sair', ['user' => $this->order->user->id])
                    : null,
            ]);
    }
}
