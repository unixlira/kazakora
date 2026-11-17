@extends('emails.layout')

@php
    $brl = fn ($v) => ((float) $v < 0 ? '− ' : '').'R$ '.number_format(abs((float) $v), 2, ',', '.');
    $cor = fn ($v) => (float) $v < 0 ? '#c81e1e' : ((float) $v > 0 ? '#0a8a23' : '#6b7280');
    $total = $auditoria['total'];
    $de = $auditoria['de']->format('d/m');
    $ate = $auditoria['ate']->copy()->subDay()->format('d/m/Y');
    $aliquota = rtrim(rtrim(number_format($auditoria['aliquota'], 2, ',', '.'), '0'), ',');
    $celula = 'padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 13px; text-align: right; white-space: nowrap;';
@endphp

@section('title', 'Auditoria da semana')
@section('preheader', 'Caiu '.$brl($total['depositado']).' · imposto '.$brl($total['imposto']).' · fornecedor '.$brl($total['fornecedor']).' · insumos '.$brl($total['insumos']).' · lucro '.$brl($total['sobra']))

@section('content')
    @include('emails.partials.titulo', ['icone' => '🧾', 'texto' => 'Dinheiro que caiu de '.$de.' a '.$ate])

    <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5; color: #374151; text-align: center;">
        Semana fechada de segunda a domingo. Do que os canais depositaram, separe:
    </p>

    {{-- O que separar, no total --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 22px; background-color: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px;">
        <tr><td style="padding: 16px 18px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-size: 14px; color: #111827; padding: 4px 0;">Caiu na conta (os 4 canais)</td>
                    <td style="font-size: 18px; font-weight: 800; text-align: right; color: #111827;">{{ $brl($total['depositado']) }}</td>
                </tr>
                <tr>
                    <td style="font-size: 14px; color: #c2410c; padding: 4px 0;">Separar para imposto (Simples {{ $aliquota }}%)</td>
                    <td style="font-size: 16px; font-weight: 700; text-align: right; color: #c2410c;">{{ $brl($total['imposto']) }}</td>
                </tr>
                <tr>
                    <td style="font-size: 14px; color: #1d4ed8; padding: 4px 0;">Separar para fornecedor (custo dos produtos)</td>
                    <td style="font-size: 16px; font-weight: 700; text-align: right; color: #1d4ed8;">{{ $brl($total['fornecedor']) }}</td>
                </tr>
                <tr>
                    <td style="font-size: 14px; color: #6d28d9; padding: 4px 0;">Separar para insumos ({{ $brl($auditoria['insumos_por_pedido']) }} por pedido embalado)</td>
                    <td style="font-size: 16px; font-weight: 700; text-align: right; color: #6d28d9;">{{ $brl($total['insumos']) }}</td>
                </tr>
                @if ((float) $total['frete_proprio'] > 0)
                    <tr>
                        <td style="font-size: 14px; color: #4b5563; padding: 4px 0;">Frete que a loja pagou nesses pedidos (Correios/Melhor Envio)</td>
                        <td style="font-size: 16px; font-weight: 700; text-align: right; color: #4b5563;">{{ $brl($total['frete_proprio']) }}</td>
                    </tr>
                @endif
                <tr>
                    <td style="font-size: 15px; font-weight: 700; color: #111827; padding: 10px 0 0; border-top: 1px solid #e5e7eb;">Lucro líquido (o que sobra)</td>
                    <td style="font-size: 22px; font-weight: 800; text-align: right; padding: 10px 0 0; border-top: 1px solid #e5e7eb; color: {{ $cor($total['sobra']) }};">{{ $brl($total['sobra']) }}</td>
                </tr>
            </table>
        </td></tr>
    </table>

    {{-- Por canal --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; border-collapse: collapse;">
        <tr>
            @foreach (['Canal', 'Caiu', 'Imposto', 'Fornecedor', 'Insumos', 'Lucro'] as $i => $titulo)
                <th style="padding: 8px 6px; border-bottom: 2px solid #111827; font-size: 11px; text-transform: uppercase; color: #4b5563; text-align: {{ $i === 0 ? 'left' : 'right' }};">{{ $titulo }}</th>
            @endforeach
        </tr>
        @foreach ($auditoria['canais'] as $canal)
            <tr>
                <td style="{{ $celula }} text-align: left; white-space: normal;">
                    <strong>{{ $canal['nome'] }}</strong>
                    <div style="font-size: 11px; color: #6b7280;">
                        {{ $canal['pedidos'] }} {{ $canal['pedidos'] === 1 ? 'pedido pago' : 'pedidos pagos' }}
                        @if ((float) $canal['descontos'] < 0) · o canal descontou {{ $brl(abs($canal['descontos'])) }} @endif
                        @if ((float) $canal['frete_proprio'] > 0) · frete próprio {{ $brl($canal['frete_proprio']) }} @endif
                    </div>
                </td>
                <td style="{{ $celula }} font-weight: 700;">{{ $brl($canal['depositado']) }}</td>
                <td style="{{ $celula }} color: #c2410c;">{{ $brl($canal['imposto']) }}</td>
                <td style="{{ $celula }} color: #1d4ed8;">{{ $brl($canal['fornecedor']) }}</td>
                <td style="{{ $celula }} color: #6d28d9;">{{ $brl($canal['insumos']) }}</td>
                <td style="{{ $celula }} font-weight: 700; color: {{ $cor($canal['sobra']) }};">{{ $brl($canal['sobra']) }}</td>
            </tr>
        @endforeach
    </table>

    @if ($auditoria['avisos'] !== [])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 12px;">
            <tr><td style="padding: 14px 16px;">
                <div style="font-size: 13px; font-weight: 700; color: #991b1b; margin: 0 0 6px;">⚠️ Confira antes de separar</div>
                @foreach ($auditoria['avisos'] as $aviso)
                    <div style="font-size: 13px; line-height: 1.5; color: #7f1d1d; margin: 0 0 4px;">• {{ $aviso }}</div>
                @endforeach
            </td></tr>
        </table>
    @endif

    <p style="margin: 0; font-size: 12px; line-height: 1.6; color: #6b7280;">
        <strong>Como é a conta.</strong> "Caiu" é o repasse líquido de cada canal na semana, já sem a comissão dele: extrato pago do TikTok e da Amazon,
        liberações do Mercado Pago (Mercado Livre) e a carteira da Shopee, menos os saques. Fornecedor é o custo cadastrado dos produtos dos pedidos pagos
        nesse repasse; imposto é {{ $aliquota }}% do valor da nota de cada um; insumos é o valor por pedido embalado (o Full do Mercado Livre não embala aqui).
        Lucro = caiu − imposto − fornecedor − insumos − frete que a loja pagou. Anúncio pago no cartão (Meta, Mercado Livre Ads) não sai daqui: ele não é
        descontado do repasse.
        @if (collect($auditoria['canais'])->sum('sem_custo') > 0)
            <br>{{ collect($auditoria['canais'])->sum('sem_custo') }} pedido(s) têm produto sem custo cadastrado: o fornecedor está subestimado e o lucro, inflado.
        @endif
    </p>
@endsection
