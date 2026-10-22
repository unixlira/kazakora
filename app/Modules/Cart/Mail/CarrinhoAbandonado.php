<?php

namespace App\Modules\Cart\Mail;

use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Catalog\Models\Product;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

/**
 * Lembrete de carrinho abandonado (pedido 2026-10-10). O botão devolve o
 * carrinho mesmo em outro aparelho (link assinado) e o rodapé tem o link
 * para parar os lembretes.
 */
class CarrinhoAbandonado extends Mailable
{
    /** Assunto de cada lembrete, do 1º ao 8º. */
    public const ASSUNTOS = [
        'Esqueceu algo no carrinho? 🛒',
        'Seus produtos ainda estão te esperando',
        'O frete grátis do seu carrinho continua valendo 🚚',
        'Ainda pensando? Seu carrinho está guardado',
        'Pague no Pix e economize no seu carrinho 💸',
        'Os produtos do seu carrinho estão saindo rápido',
        'Falta pouco para seus produtos chegarem ⚡',
        'Último lembrete: seu carrinho vai expirar',
    ];

    public function __construct(
        public readonly CartSnapshot $carrinho,
        public readonly int $numero = 1,
    ) {
    }

    public function build(): self
    {
        $itens = $this->carrinho->itens ?? [];
        $produtos = Product::query()
            ->with(['images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('position')])
            ->whereIn('id', array_keys($itens))
            ->where('is_active', true)
            ->get();

        $linhas = $produtos->map(fn (Product $produto) => [
            'nome' => $produto->name,
            'quantidade' => (int) $itens[$produto->id],
            'valor' => $produto->final_price * (int) $itens[$produto->id],
            'imagem' => $produto->images->first()?->thumb_url,
        ])->values()->all();

        $indice = max(0, min($this->numero, count(self::ASSUNTOS)) - 1);

        return $this
            ->subject(self::ASSUNTOS[$indice])
            ->view('emails.carrinho-abandonado', [
                'linhas' => $linhas,
                'total' => array_sum(array_column($linhas, 'valor')),
                'nome' => $this->carrinho->user?->name ? strtok($this->carrinho->user->name, ' ') : null,
                'ultimo' => $this->numero >= count(self::ASSUNTOS),
                'linkCarrinho' => URL::signedRoute('carrinho.recuperar', ['carrinho' => $this->carrinho->id]),
                'linkParar' => URL::signedRoute('carrinho.lembretes.parar', ['carrinho' => $this->carrinho->id]),
            ]);
    }
}
