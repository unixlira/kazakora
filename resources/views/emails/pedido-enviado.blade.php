@extends('emails.layout')

@section('title', "Pedido {$order->numero} enviado")
@section('preheader', "Seu pedido {$order->numero} saiu para entrega. Agora falta pouco.")

@section('content')
    @php
        // Mesmo layout do pedido confirmado (28/11/2026): celular primeiro, sem o quadro laranja.
        $marca = $marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order);
        $cor = $marca['cor'];
        $primeiroNome = strtok((string) $order->shipping_name, ' ') ?: $order->shipping_name;
        $rotulo = 'font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280;';
    @endphp

    @include('emails.partials.status-pedido', ['numero' => $order->numero, 'titulo' => 'Seu pedido foi enviado', 'cor' => $cor, 'passo' => 3])

    <p style="margin: 0 0 22px; font-size: 15px; line-height: 23px; color: #374151;">
        Olá, {{ $primeiroNome }}. Seu pedido <strong style="color: {{ $cor }};">{{ $order->numero }}</strong> acabou de sair para entrega.
        Agora falta pouco para a sua compra {{ $marca['nome'] ?? 'KazaKora' }} chegar até você.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0; background-color: #f8f9fb; border: 1px solid #eceef1; border-radius: 12px;">
        <tr>
            <td style="padding: 18px; font-family: Arial, Helvetica, sans-serif;">
                <div style="{{ $rotulo }}">Previsão de entrega</div>
                <div style="margin-top: 4px; font-size: 20px; line-height: 26px; font-weight: 800; color: #14202e;">{{ ucfirst($previsao) }}</div>
                @if ($codigoRastreio)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 14px; border-top: 1px solid #eceef1;">
                        <tr>
                            <td style="padding-top: 12px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 18px; color: #6b7280;">Código de rastreio</td>
                            <td align="right" style="padding-top: 12px; font-family: 'Courier New', monospace; font-size: 15px; line-height: 18px; font-weight: 700; color: #14202e; text-align: right; white-space: nowrap;">{{ $codigoRastreio }}</td>
                        </tr>
                    </table>
                @endif
            </td>
        </tr>
    </table>

    @include('emails.partials.botao', ['url' => $linkRastreio, 'texto' => 'Acompanhar a entrega', 'cor' => '#0FB930', 'largo' => true])

    @if ($order->items->isNotEmpty())
        <div style="{{ $rotulo }} margin: 4px 0 2px;">O que está a caminho</div>
        @include('emails.partials.itens-do-pedido', ['order' => $order])
    @endif
@endsection
