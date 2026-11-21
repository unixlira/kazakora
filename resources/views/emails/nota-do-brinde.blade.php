@extends('emails.layout')

@section('title', "Notas fiscais do pedido {$order->numero}")
@section('preheader', 'Segue a nota fiscal do seu brinde, junto com a nota da sua compra.')

@section('content')
    @include('emails.partials.titulo', ['texto' => 'Segue a nota fiscal do seu brinde', 'icone' => '🎁'])

    <p style="margin: 0 0 16px; text-align: center; color: #374151;">
        Olá, {{ $order->shipping_name }}. Na {{ $marcaEmail['nome'] ?? 'KazaKora' }}, presente também vai com nota.
        Seguem anexas as notas fiscais do seu pedido <strong style="color: {{ ($marcaEmail ?? [])['cor'] ?? '#F27A2A' }};">{{ $order->numero }}</strong>:
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; background-color: #fff7ed; border: 1px solid #fed7aa; border-radius: 14px;">
        <tr>
            <td style="padding: 16px 18px; color: #111827; font-size: 15px; line-height: 1.6;">
                <div>📄 <strong>Nota da compra</strong>{{ $order->invoice?->numero ? " (NF-e {$order->invoice->numero})" : '' }}</div>
                <div>🎁 <strong>Nota do brinde</strong>{{ $bonificacao->invoice?->numero ? " (NF-e {$bonificacao->invoice->numero})" : '' }}@if ($brindes->isNotEmpty()): {{ $brindes->implode(', ') }}@endif</div>
            </td>
        </tr>
    </table>

    <p style="margin: 0 0 6px; text-align: center; color: #374151;">
        Tudo em dia, tudo transparente: é assim que a gente cuida de cada entrega.
    </p>
@endsection
