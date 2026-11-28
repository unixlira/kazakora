{{--
    Resumo de valores do pedido nos e-mails (28/11/2026): subtotal, frete,
    cada desconto numa linha (cupom, inclusive o LEVE2 da 2ª unidade, Pix e
    plataforma) e o total. Rótulo à esquerda e valor à direita, sempre
    alinhados. $order obrigatório.
--}}
@php
    $dinheiro = fn ($valor) => 'R$ '.number_format((float) $valor, 2, ',', '.');
    $qtdItens = (int) $order->items->sum('quantity');
    $leveDois = (float) $order->items->sum(fn ($item) => (float) ($item->leve2_desconto ?? 0)) > 0;
    $cupom = (float) $order->discount_amount;
    $rotuloCupom = $order->coupon_code
        ? 'Cupom '.$order->coupon_code.($leveDois ? ' (2ª unidade)' : '')
        : 'Desconto';
    $linhasResumo = [
        ['Subtotal ('.$qtdItens.' '.($qtdItens === 1 ? 'item' : 'itens').')', $dinheiro($order->subtotal), false],
        ['Frete', (float) $order->shipping_cost > 0 ? $dinheiro($order->shipping_cost) : 'Grátis', (float) $order->shipping_cost <= 0],
    ];
    if ($cupom > 0) {
        $linhasResumo[] = [$rotuloCupom, '- '.$dinheiro($cupom), true];
    }
    if ((float) $order->desconto_plataforma_amount > 0) {
        $linhasResumo[] = ['Desconto da plataforma', '- '.$dinheiro($order->desconto_plataforma_amount), true];
    }
    if ((float) $order->pix_discount_amount > 0) {
        $linhasResumo[] = ['Desconto no Pix', '- '.$dinheiro($order->pix_discount_amount), true];
    }
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 4px 0 0; border-top: 1px solid #eceef1; font-family: Arial, Helvetica, sans-serif;">
    @foreach ($linhasResumo as [$rotulo, $valor, $verde])
        @php $espaco = ($loop->first ? '14px' : '4px').' 0 '.($loop->last ? '12px' : '4px'); @endphp
        <tr>
            <td style="padding: {{ $espaco }}; font-size: 14px; line-height: 20px; color: #4b5563;">{{ $rotulo }}</td>
            <td align="right" style="padding: {{ $espaco }}; font-size: 14px; line-height: 20px; text-align: right; white-space: nowrap; color: {{ $verde ? '#0a8a23' : '#14202e' }}; font-weight: {{ $verde ? '700' : '400' }};">{{ $valor }}</td>
        </tr>
    @endforeach
    <tr>
        <td style="padding: 12px 0 0; border-top: 1px solid #eceef1; font-size: 16px; line-height: 22px; font-weight: 800; color: #14202e;">Total</td>
        <td align="right" style="padding: 12px 0 0; border-top: 1px solid #eceef1; font-size: 20px; line-height: 22px; font-weight: 800; color: #14202e; text-align: right; white-space: nowrap;">{{ $dinheiro($order->total) }}</td>
    </tr>
</table>
