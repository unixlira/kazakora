{{--
    Itens de um pedido nos e-mails (28/11/2026). Monta as linhas de
    emails.partials.itens a partir do pedido: foto principal do produto e, no
    item com o cupom da 2ª unidade (LeveDois), a linha verde do desconto.
    $brindes (opcional): livros digitais que entram como itens de valor zero
    (pedido do Lira 2026-10-22).
--}}
@php
    $linhasDoPedido = $order->items->map(function ($item) {
        $desconto = (float) ($item->leve2_desconto ?? 0);

        return [
            'nome' => $item->product_name,
            'quantidade' => $item->quantity,
            'valor' => $item->subtotal,
            'imagem' => rescue(fn () => $item->product?->images()->orderByDesc('is_primary')->orderBy('position')->first()?->thumb_url, null, false),
            'desconto' => $desconto > 0
                ? ((int) $item->leve2_unidades > 1 ? $item->leve2_unidades.' unidades' : '2ª unidade').' com desconto: - R$ '.number_format($desconto, 2, ',', '.')
                : null,
        ];
    })->all();

    foreach (! empty($brindes) ? $brindes : [] as $livro) {
        $linhasDoPedido[] = ['nome' => $livro->titulo.' (livro digital em PDF)', 'quantidade' => 1, 'valor' => 0, 'imagem' => $livro->capa_url, 'icone' => '📘', 'detalhe' => 'Presente'];
    }
@endphp
@include('emails.partials.itens', ['linhas' => $linhasDoPedido])
