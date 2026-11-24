{{-- Pedido de avaliação 5 dias depois da entrega (pedido do Lira 2026-11-23). Link assinado, vale 30 dias. --}}
@extends('emails.layout')

@section('title', 'O que você achou da sua compra?')
@section('preheader', "Conte em poucos segundos o que achou do pedido {$numero}.")

@section('content')
    @include('emails.partials.titulo', ['icone' => '⭐', 'texto' => "{$nome}, o que você achou?"])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        Seu pedido <strong>{{ $numero }}</strong> chegou há alguns dias. Conte para a gente e para quem ainda vai comprar o que achou: leva menos de um minuto e vale qualquer nota, de 1 a 5 estrelas.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 8px;">
        @foreach ($produtos as $produto)
            <tr>
                <td style="padding: 8px 0; border-top: 1px solid #eef0f2; width: 64px;">
                    @if ($produto['imagem'])
                        <img src="{{ $produto['imagem'] }}" alt="" width="56" height="56" style="display: block; width: 56px; height: 56px; object-fit: cover; border-radius: 8px;">
                    @endif
                </td>
                <td style="padding: 8px 0 8px 12px; border-top: 1px solid #eef0f2; font-size: 14px; color: #14202e;">
                    {{ $produto['nome'] }}<br>
                    <span style="color: #f5a623; font-size: 18px; letter-spacing: 2px;">☆☆☆☆☆</span>
                </td>
            </tr>
        @endforeach
    </table>

    @include('emails.partials.botao', ['url' => $link, 'texto' => 'Avaliar minha compra', 'cor' => $marcaEmail['cor'] ?? '#f27a2a'])

    <p style="margin: 0; text-align: center; color: #9ca3af; font-size: 12px;">
        O link é só seu e vale por 30 dias. Teve algum problema com o pedido? Fale com a gente pelo WhatsApp no fim deste e-mail.
    </p>
@endsection
