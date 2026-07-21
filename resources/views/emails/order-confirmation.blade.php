<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
    </head>
    <body style="font-family: sans-serif; color: #111827;">
        <h1 style="font-size: 18px;">Pedido #{{ $order->id }} confirmado</h1>

        <p>Olá, {{ $order->shipping_name }}! Recebemos seu pedido e ele já está sendo preparado.</p>

        <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
            <thead>
                <tr>
                    <th style="text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px 0;">Produto</th>
                    <th style="text-align: center; border-bottom: 1px solid #e5e7eb; padding: 8px 0;">Qtd.</th>
                    <th style="text-align: right; border-bottom: 1px solid #e5e7eb; padding: 8px 0;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">{{ $item->product_name }}</td>
                        <td style="padding: 8px 0; text-align: center; border-bottom: 1px solid #f3f4f6;">{{ $item->quantity }}</td>
                        <td style="padding: 8px 0; text-align: right; border-bottom: 1px solid #f3f4f6;">R$ {{ number_format($item->subtotal, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin-top: 16px; font-weight: bold;">
            Total: R$ {{ number_format($order->total, 2, ',', '.') }}
        </p>

        <p style="margin-top: 24px;">
            Endereço de entrega:<br>
            {{ $order->shipping_street }}, {{ $order->shipping_number }}
            @if ($order->shipping_complement) - {{ $order->shipping_complement }} @endif<br>
            {{ $order->shipping_neighborhood }} - {{ $order->shipping_city }}/{{ $order->shipping_state }}<br>
            CEP {{ $order->shipping_zip }}
        </p>
    </body>
</html>
