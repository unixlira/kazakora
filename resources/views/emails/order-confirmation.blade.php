@extends('emails.layout')

@section('title', "Pedido #{$order->id} confirmado")
@section('preheader', "Seu pedido #{$order->id} foi confirmado e já está sendo preparado.")

@section('content')
    @php
        $invoiceAuthorized = $order->invoice?->status === \App\Modules\Fiscal\Models\Invoice::STATUS_AUTHORIZED;
        $linhas = $order->items->map(fn ($item) => [
            'nome' => $item->product_name,
            'quantidade' => $item->quantity,
            'valor' => $item->subtotal,
            'imagem' => rescue(fn () => $item->product?->images()->orderByDesc('is_primary')->orderBy('position')->first()?->thumb_url, null, false),
        ])->all();
    @endphp

    @include('emails.partials.titulo', ['icone' => '🎉', 'texto' => "Pedido #{$order->id} confirmado!"])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        Olá, {{ $order->shipping_name }}! Recebemos seu pagamento e seu pedido já está sendo preparado com carinho.
    </p>

    @if ($invoiceAuthorized)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 20px; background-color: #ecfdf3; border: 1px solid #b7ebc6; border-radius: 10px;">
            <tr>
                <td style="padding: 14px 18px; font-size: 13px; color: #065f46;">
                    <strong>&#128206; Nota fiscal em anexo.</strong> A NF-e do seu pedido está anexada a este e-mail em PDF e XML.<br>
                    <span style="font-size: 11px; color: #4b7a63; word-break: break-all;">Chave de acesso: {{ $order->invoice->chave_acesso }}</span>
                </td>
            </tr>
        </table>
    @endif

    @include('emails.partials.itens', ['linhas' => $linhas])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; color: #374151;">
        <tr>
            <td style="padding: 4px 0;">Subtotal</td>
            <td style="padding: 4px 0; text-align: right;">R$ {{ number_format((float) $order->subtotal, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 0;">Frete</td>
            <td style="padding: 4px 0; text-align: right; color: #0a8a23; font-weight: 700;">{{ (float) $order->shipping_cost > 0 ? 'R$ '.number_format((float) $order->shipping_cost, 2, ',', '.') : 'Grátis' }}</td>
        </tr>
        @if ((float) $order->discount_amount + (float) $order->pix_discount_amount > 0)
            <tr>
                <td style="padding: 4px 0;">Descontos</td>
                <td style="padding: 4px 0; text-align: right; color: #0a8a23;">- R$ {{ number_format((float) $order->discount_amount + (float) $order->pix_discount_amount, 2, ',', '.') }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 10px 0 0; border-top: 2px solid #eef0f3; font-size: 16px; font-weight: 800; color: #111827;">Total</td>
            <td style="padding: 10px 0 0; border-top: 2px solid #eef0f3; text-align: right; font-size: 18px; font-weight: 800; color: #111827;">R$ {{ number_format((float) $order->total, 2, ',', '.') }}</td>
        </tr>
    </table>

    @include('emails.partials.botao', ['url' => route('pedidos.meus'), 'texto' => 'Acompanhar meu pedido', 'cor' => '#0FB930'])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8fafc; border-radius: 10px;">
        <tr>
            <td style="padding: 14px 18px; font-size: 13px; color: #4b5563;">
                <strong style="color: #111827;">&#128205; Endereço de entrega</strong><br>
                {{ $order->shipping_street }}, {{ $order->shipping_number }}
                @if ($order->shipping_complement) - {{ $order->shipping_complement }} @endif<br>
                {{ $order->shipping_neighborhood }} - {{ $order->shipping_city }}/{{ $order->shipping_state }}<br>
                CEP {{ $order->shipping_zip }}
            </td>
        </tr>
    </table>
@endsection
