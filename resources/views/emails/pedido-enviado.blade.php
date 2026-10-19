@extends('emails.layout')

@section('title', "Pedido {$order->numero} enviado")
@section('preheader', "Seu pedido {$order->numero} saiu para entrega. Agora falta pouco.")

@section('content')
    @include('emails.partials.titulo', ['texto' => 'Seu pedido foi enviado', 'icone' => '🚚'])

    <p style="margin: 0 0 18px; text-align: center; color: #374151;">
        Olá, {{ $order->shipping_name }}. Seu pedido <strong style="color: #F27A2A;">{{ $order->numero }}</strong> acabou de sair para entrega.
        Agora falta pouco para a sua compra {{ $marcaEmail['nome'] ?? 'KazaKora' }} chegar até você.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; background-color: #fff7ed; border: 1px solid #fed7aa; border-radius: 14px;">
        <tr>
            <td style="padding: 18px; text-align: center;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #9a3412;">Previsão de entrega</div>
                <div style="font-size: 20px; font-weight: 800; color: #111827;">{{ ucfirst($previsao) }}</div>
                @if ($codigoRastreio)
                    <div style="margin-top: 6px; font-size: 13px; color: #4b5563;">Código de rastreio: <strong>{{ $codigoRastreio }}</strong></div>
                @endif
            </td>
        </tr>
    </table>

    @include('emails.partials.botao', ['url' => $linkRastreio, 'texto' => 'Acompanhar a entrega', 'cor' => '#0FB930'])
@endsection
