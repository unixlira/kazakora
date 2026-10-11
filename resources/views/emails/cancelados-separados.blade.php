@extends('emails.layout')

@php
    $canais = ['mercado_livre' => 'Mercado Livre', 'shopee' => 'Shopee'];
@endphp

@section('title', 'Pedidos separados cancelados')
@section('preheader', count($pedidos).' pedido(s) já separado(s) foram cancelados pelo comprador. Tire da expedição.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '⚠️', 'texto' => count($pedidos) === 1 ? 'Um pedido separado foi cancelado' : count($pedidos).' pedidos separados foram cancelados'])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        {{ count($pedidos) === 1 ? 'Este pedido já tinha sido separado' : 'Estes pedidos já tinham sido separados' }} para envio e o comprador cancelou.
        <strong>Não envie</strong> — tire da expedição e devolva os produtos ao estoque.
    </p>

    @foreach ($pedidos as $pedido)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 12px; border: 1px solid #fecaca; background-color: #fef2f2; border-radius: 12px;">
            <tr>
                <td style="padding: 14px 16px; font-size: 14px; color: #111827;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="font-weight: 800;">Pedido #{{ $pedido['id'] }}</td>
                            <td style="text-align: right;">
                                <span style="display: inline-block; padding: 3px 10px; border-radius: 999px; background-color: #111827; color: #ffffff; font-size: 11px; font-weight: 700;">{{ $canais[$pedido['origin']] ?? $pedido['origin'] }}</span>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top: 4px; font-size: 12px; color: #6b7280;">
                        Pedido no canal: {{ $pedido['external_order_id'] ?: '—' }}
                        @if ($pedido['external_shipment_id']) · envio {{ $pedido['external_shipment_id'] }} @endif
                        <br>Separado em {{ \Illuminate\Support\Carbon::parse($pedido['packed_at'])->format('d/m H:i') }}
                    </div>
                    @if (! empty($pedido['items']))
                        <div style="margin-top: 8px; font-size: 13px;">
                            @foreach ($pedido['items'] as $item)
                                <div>• {{ $item['quantity'] }}× {{ $item['product_name'] }}</div>
                            @endforeach
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    @endforeach
@endsection
