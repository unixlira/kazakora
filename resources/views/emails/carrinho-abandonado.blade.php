@extends('emails.layout')

@section('title', 'Seu carrinho está te esperando')
@section('preheader', 'Seus produtos continuam no carrinho, com frete grátis.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '🛒', 'texto' => $nome ? "{$nome}, seu carrinho está te esperando!" : 'Seu carrinho está te esperando!'])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        @if ($ultimo)
            Este é o nosso último lembrete. Guardamos seus produtos, mas o estoque é limitado.
        @else
            Você deixou estes produtos no carrinho. Eles continuam guardados para você — e com <strong>frete grátis</strong>.
        @endif
    </p>

    @include('emails.partials.itens', ['linhas' => $linhas])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding: 10px 0 0; font-size: 16px; font-weight: 800; color: #111827;">Total</td>
            <td style="padding: 10px 0 0; text-align: right; font-size: 18px; font-weight: 800; color: #111827;">R$ {{ number_format((float) $total, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td colspan="2" style="padding: 2px 0 0; text-align: right; font-size: 12px; color: #0a8a23; font-weight: 700;">Frete grátis &middot; desconto extra pagando no Pix</td>
        </tr>
    </table>

    @include('emails.partials.botao', ['url' => $linkCarrinho, 'texto' => 'Finalizar minha compra', 'cor' => '#0FB930'])

    <p style="margin: 0; text-align: center; color: #9ca3af; font-size: 12px;">
        Já comprou ou desistiu? <a href="{{ $linkParar }}" style="color: #9ca3af;">Não quero mais receber lembretes deste carrinho</a>.
    </p>
@endsection
