@extends('emails.layout')

@section('title', "Pedido #{$order->id} confirmado")

@section('content')
    @php($invoiceAuthorized = $order->invoice?->status === \App\Modules\Fiscal\Models\Invoice::STATUS_AUTHORIZED)

    <h1 style="margin: 0 0 16px; font-family: Georgia, 'Times New Roman', serif; font-size: 22px; font-weight: 600; color: #1b3a5c;">
        Pedido #{{ $order->id }} confirmado
    </h1>

    <p style="margin: 0 0 16px;">
        Olá, {{ $order->shipping_name }}! Recebemos seu pedido e ele já está sendo preparado.
    </p>

    @if ($invoiceAuthorized)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 20px; background-color: #fbe4d2; border-radius: 8px;">
            <tr>
                <td style="padding: 14px 18px; font-size: 13px; color: #1b3a5c;">
                    <strong>Nota fiscal em anexo.</strong> A NF-e do seu pedido (chave de acesso
                    {{ $order->invoice->chave_acesso }}) está anexada a este e-mail em PDF.
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 0 0 8px;">
        <thead>
            <tr>
                <td style="text-align: left; border-bottom: 2px solid #e8ebf1; padding: 8px 0; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #8089a0;">Produto</td>
                <td style="text-align: center; border-bottom: 2px solid #e8ebf1; padding: 8px 0; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #8089a0;">Qtd.</td>
                <td style="text-align: right; border-bottom: 2px solid #e8ebf1; padding: 8px 0; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #8089a0;">Subtotal</td>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td style="padding: 10px 0; border-bottom: 1px solid #f3f4f6;">{{ $item->product_name }}</td>
                    <td style="padding: 10px 0; text-align: center; border-bottom: 1px solid #f3f4f6;">{{ $item->quantity }}</td>
                    <td style="padding: 10px 0; text-align: right; border-bottom: 1px solid #f3f4f6;">R$ {{ number_format($item->subtotal, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding: 12px 0; text-align: right; font-weight: 700; font-size: 16px; color: #1b3a5c;">
                Total: R$ {{ number_format($order->total, 2, ',', '.') }}
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 16px 0 24px;">
        <tr>
            <td style="border-radius: 8px; background-color: #f27a2a;">
                <a href="{{ route('pedidos.meus') }}" style="display: inline-block; padding: 12px 28px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                    Acompanhar pedido
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 0 0 4px; font-size: 13px; font-weight: 600; color: #1b3a5c;">Endereço de entrega</p>
    <p style="margin: 0; color: #526075; font-size: 13px;">
        {{ $order->shipping_street }}, {{ $order->shipping_number }}
        @if ($order->shipping_complement) - {{ $order->shipping_complement }} @endif<br>
        {{ $order->shipping_neighborhood }} - {{ $order->shipping_city }}/{{ $order->shipping_state }}<br>
        CEP {{ $order->shipping_zip }}
    </p>
@endsection
