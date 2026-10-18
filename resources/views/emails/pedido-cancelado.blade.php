@extends('emails.layout')

@section('title', "Pedido {$order->numero} cancelado")
@section('preheader', "Seu pedido {$order->numero} foi cancelado.")

@section('content')
    @include('emails.partials.titulo', ['texto' => "Pedido {$order->numero} cancelado"])

    <p style="margin: 0 0 18px; text-align: center; color: #374151;">
        Olá, {{ $order->shipping_name }}. Seu pedido <strong style="color: #F27A2A;">{{ $order->numero }}</strong> foi cancelado e não será enviado.
    </p>

    @if ($valorEstornado > 0)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; background-color: #ecfdf3; border: 1px solid #b7ebc6; border-radius: 14px;">
            <tr>
                <td style="padding: 18px; text-align: center;">
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #065f46;">Estorno solicitado</div>
                    <div style="font-size: 28px; font-weight: 800; color: #111827;">R$ {{ number_format($valorEstornado, 2, ',', '.') }}</div>
                    <div style="font-size: 13px; color: #4b5563;">O valor volta pela mesma forma de pagamento. O prazo para aparecer depende do seu banco ou cartão.</div>
                </td>
            </tr>
        </table>
    @endif

    @include('emails.partials.botao', ['url' => ($marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order))['pedido'], 'texto' => 'Ver meu pedido', 'cor' => '#0FB930'])
@endsection
