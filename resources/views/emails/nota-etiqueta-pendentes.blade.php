@extends('emails.layout')

@php
    $canais = ['mercado_livre' => 'Mercado Livre', 'shopee' => 'Shopee', 'tiktok_shop' => 'TikTok Shop', 'amazon' => 'Amazon', 'loja' => 'Site'];
    $notas = ['pending' => 'na fila', 'signed' => 'assinada, não enviada', 'sent' => 'enviada, sem retorno', 'rejected' => 'rejeitada', 'denied' => 'denegada', 'cancelled' => 'cancelada', 'error' => 'com erro'];
@endphp

@section('title', 'Pedidos sem nota e sem etiqueta')
@section('preheader', count($pedidos).' pedido(s) pago(s) sem NF-e autorizada e sem etiqueta impressa. Risco de atraso no despacho.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '🚨', 'texto' => count($pedidos) === 1 ? 'Um pedido pago está travado' : count($pedidos).' pedidos pagos estão travados'])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        {{ count($pedidos) === 1 ? 'Este pedido está pago, mas' : 'Estes pedidos estão pagos, mas' }} a nota fiscal não foi autorizada e a etiqueta não foi impressa nem separada no KoraSync.
        <strong>Confira a nota</strong> para não atrasar o envio.
    </p>

    @foreach ($pedidos as $pedido)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 12px; border: 1px solid #fecaca; background-color: #fef2f2; border-radius: 12px;">
            <tr>
                <td style="padding: 14px 16px; font-size: 14px; color: #111827;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="font-weight: 800;">
                                <a href="{{ url('/admin/pedidos/'.$pedido['id']) }}" style="color: #111827;">Pedido #{{ $pedido['id'] }}</a>
                                @if ($pedido['novo']) <span style="color: #b91c1c; font-size: 11px;">NOVO</span> @endif
                            </td>
                            <td style="text-align: right;">
                                <span style="display: inline-block; padding: 3px 10px; border-radius: 999px; background-color: #111827; color: #ffffff; font-size: 11px; font-weight: 700;">{{ $canais[$pedido['origin']] ?? $pedido['origin'] }}</span>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top: 4px; font-size: 12px; color: #6b7280;">
                        Pedido no canal: {{ $pedido['external_order_id'] ?: '—' }}
                        <br>Criado em {{ \Illuminate\Support\Carbon::parse($pedido['created_at'])->format('d/m H:i') }}
                        <br>Nota: <strong style="color: #b91c1c;">{{ $pedido['nota'] ? ($notas[$pedido['nota']] ?? $pedido['nota']) : 'não gerada' }}</strong>
                        @if ($pedido['motivo']) ({{ \Illuminate\Support\Str::limit($pedido['motivo'], 160) }}) @endif
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
