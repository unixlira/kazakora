<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Catalog\Support\PedidoDeAvaliacao;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;

/**
 * "Conta pra gente: o que achou?" (pedido do Lira 2026-11-23): 5 dias depois
 * da entrega, o link assinado para o cliente avaliar cada produto do pedido.
 * Marca da loja do pedido (FerraKora com a dela). Sai por loja:pedir-avaliacao.
 */
class PedidoAvaliacao extends Mailable
{
    /** @param  Collection<int, OrderItem>  $itens */
    public function __construct(
        public readonly Order $order,
        public readonly Collection $itens,
        public readonly string $link,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::doPedido($this->order);
        $nome = PedidoDeAvaliacao::primeiroNome($this->order);

        return $this
            ->when(PedidoDeAvaliacao::ferrakora($this->order), fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject("{$nome}, o que você achou da sua compra?")
            ->view('emails.pedido-avaliacao', [
                'marcaEmail' => $marca,
                'nome' => $nome,
                'numero' => $this->order->numero,
                'link' => $this->link,
                'produtos' => $this->itens->map(fn (OrderItem $item) => [
                    'nome' => PedidoDeAvaliacao::nomeDoItem($item),
                    'imagem' => $item->product?->images->first()?->thumb_url ?? $item->product?->parent?->images->first()?->thumb_url,
                ])->all(),
            ]);
    }
}
