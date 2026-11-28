@extends('emails.layout')

@section('title', "Pedido {$order->numero} cancelado")
@section('preheader', "Seu pedido {$order->numero} foi cancelado.")

@section('content')
    @php
        // Mesmo layout do pedido confirmado (28/11/2026): celular primeiro, alinhado à esquerda.
        $marca = $marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order);
        $cor = $marca['cor'];
        $primeiroNome = strtok((string) $order->shipping_name, ' ') ?: $order->shipping_name;
        $rotulo = 'font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280;';
    @endphp

    @include('emails.partials.status-pedido', ['numero' => $order->numero, 'titulo' => 'Pedido cancelado', 'cor' => $cor, 'aviso' => 'Cancelado'])

    <p style="margin: 0 0 22px; font-size: 15px; line-height: 23px; color: #374151;">
        Olá, {{ $primeiroNome }}. Seu pedido <strong style="color: {{ $cor }};">{{ $order->numero }}</strong> foi cancelado e não será enviado.
    </p>

    @if ($valorEstornado > 0)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0; background-color: #f8f9fb; border: 1px solid #eceef1; border-radius: 12px;">
            <tr>
                <td style="padding: 18px; font-family: Arial, Helvetica, sans-serif;">
                    <div style="{{ $rotulo }}">Estorno solicitado</div>
                    <div style="margin-top: 4px; font-size: 28px; line-height: 34px; font-weight: 800; color: #0a8a23;">R$ {{ number_format($valorEstornado, 2, ',', '.') }}</div>
                    <div style="margin-top: 4px; font-size: 13px; line-height: 19px; color: #4b5563;">O valor volta pela mesma forma de pagamento. O prazo para aparecer depende do seu banco ou cartão.</div>
                </td>
            </tr>
        </table>
    @endif

    @include('emails.partials.botao', ['url' => $marca['pedido'], 'texto' => 'Ver meu pedido', 'cor' => '#0FB930', 'largo' => true])

    @if ($order->items->isNotEmpty())
        <div style="{{ $rotulo }} margin: 4px 0 2px;">Itens cancelados</div>
        @include('emails.partials.itens-do-pedido', ['order' => $order])
    @endif
@endsection
