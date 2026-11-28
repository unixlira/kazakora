@extends('emails.layout')

@section('title', 'Seu carrinho está te esperando')
@section('preheader', 'Seus produtos continuam no carrinho, com frete grátis.')

@section('content')
    {{-- Mesmo layout dos e-mails de pedido (28/11/2026): celular primeiro, alinhado à esquerda. --}}
    <h1 style="margin: 0 0 12px; font-family: Arial, Helvetica, sans-serif; font-size: 24px; line-height: 30px; font-weight: 800; color: #14202e;">
        &#128722; {{ $nome ? "{$nome}, seu carrinho está te esperando!" : 'Seu carrinho está te esperando!' }}
    </h1>

    <p style="margin: 0 0 18px; font-size: 15px; line-height: 23px; color: #374151;">
        @if ($ultimo)
            Este é o nosso último lembrete. Guardamos seus produtos, mas o estoque é limitado.
        @else
            Você deixou estes produtos no carrinho. Eles continuam guardados para você — e com <strong>frete grátis</strong>.
        @endif
    </p>

    @include('emails.partials.itens', ['linhas' => $linhas])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top: 1px solid #eceef1; font-family: Arial, Helvetica, sans-serif;">
        <tr>
            <td style="padding: 14px 0 0; font-size: 16px; line-height: 22px; font-weight: 800; color: #14202e;">Total</td>
            <td align="right" style="padding: 14px 0 0; text-align: right; font-size: 20px; line-height: 22px; font-weight: 800; color: #14202e; white-space: nowrap;">R$ {{ number_format((float) $total, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td colspan="2" align="right" style="padding: 4px 0 0; text-align: right; font-size: 12px; line-height: 16px; color: #0a8a23; font-weight: 700;">Frete grátis &middot; desconto extra pagando no Pix</td>
        </tr>
    </table>

    @include('emails.partials.botao', ['url' => $linkCarrinho, 'texto' => 'Finalizar minha compra', 'cor' => '#0FB930', 'largo' => true])

    <p style="margin: 0; font-size: 12px; line-height: 18px; color: #9ca3af;">
        Já comprou ou desistiu? <a href="{{ $linkParar }}" style="color: #9ca3af;">Não quero mais receber lembretes deste carrinho</a>.
    </p>
@endsection
