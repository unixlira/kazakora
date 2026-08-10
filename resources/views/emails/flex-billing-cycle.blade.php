@extends('emails.layout')

@section('title', 'Fechamento Flex')

@section('content')
    <h1 style="margin: 0 0 16px; font-family: Georgia, 'Times New Roman', serif; font-size: 22px; font-weight: 600; color: #1b3a5c;">
        Fechamento do ciclo Mercado Envios Flex
    </h1>

    <p style="margin: 0 0 16px;">
        Período de {{ $period->period_start->format('d/m/Y') }} a {{ $period->period_end->format('d/m/Y') }}.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 24px; border-collapse: collapse;">
        <tr>
            <td style="padding: 12px 16px; border: 1px solid #e2e8f0; font-size: 13px; color: #526075;">Número de fretes Flex</td>
            <td style="padding: 12px 16px; border: 1px solid #e2e8f0; font-size: 15px; font-weight: 600; text-align: right;">{{ $period->deliveries_count }}</td>
        </tr>
        <tr>
            <td style="padding: 12px 16px; border: 1px solid #e2e8f0; font-size: 13px; color: #526075;">Valor por entrega</td>
            <td style="padding: 12px 16px; border: 1px solid #e2e8f0; font-size: 15px; font-weight: 600; text-align: right;">R$ {{ number_format($period->cost_per_delivery, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding: 12px 16px; border: 1px solid #e2e8f0; background-color: #fdf1ea; font-size: 14px; font-weight: 700; color: #1b3a5c;">Valor total a pagar</td>
            <td style="padding: 12px 16px; border: 1px solid #e2e8f0; background-color: #fdf1ea; font-size: 18px; font-weight: 700; color: #1b3a5c; text-align: right;">R$ {{ number_format($period->total_amount, 2, ',', '.') }}</td>
        </tr>
    </table>

    <p style="margin: 0; color: #526075; font-size: 13px;">
        Calculado automaticamente a partir dos envios Mercado Livre com logística "self_service" (Flex) confirmados nesse período.
    </p>
@endsection
