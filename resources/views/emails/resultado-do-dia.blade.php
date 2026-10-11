@extends('emails.layout')

@php
    $brl = fn ($v) => ((float) $v < 0 ? '− ' : '').'R$ '.number_format(abs((float) $v), 2, ',', '.');
    $cor = fn ($v) => (float) $v < 0 ? '#c81e1e' : ((float) $v > 0 ? '#0a8a23' : '#6b7280');
    $margem = fn ($lucro, $receita) => (float) $receita > 0 ? number_format((float) $lucro / (float) $receita * 100, 1, ',', '.').'%' : '—';
    $maiorLucro = max(1, max(array_map(fn ($d) => abs((float) $d['lucro']), $resultado['dias'] ?: [['lucro' => 1]])));
    $dia = $resultado['dia'];
    $mes = $resultado['mes'];
    $aliquota = rtrim(rtrim(number_format($resultado['aliquota'], 2, ',', '.'), '0'), ',');
@endphp

@section('title', 'Resultado do dia')
@section('preheader', 'Lucro líquido do dia '.$brl($dia['lucro']).' · mês '.$brl($mes['lucro']).' · impostos do mês '.$brl($mes['impostos']))

@section('content')
    @include('emails.partials.titulo', ['icone' => '📊', 'texto' => 'Resultado de '.$resultado['data']->format('d/m/Y')])

    {{-- Os três números principais --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 22px;">
        <tr>
            <td style="padding: 0 0 10px;">
                @php($negativo = (float) $dia['lucro'] < 0)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: {{ $negativo ? '#fef2f2' : '#f0fdf4' }}; border: 1px solid {{ $negativo ? '#fecaca' : '#bbf7d0' }}; border-radius: 14px;">
                    <tr><td style="padding: 18px 20px; text-align: center;">
                        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: {{ $negativo ? '#991b1b' : '#166534' }};">{{ $negativo ? '📉' : '📈' }} Lucro líquido do dia</div>
                        <div style="margin: 4px 0 2px; font-size: 34px; line-height: 1.1; font-weight: 800; color: {{ $cor($dia['lucro']) }};">{{ $brl($dia['lucro']) }}</div>
                        <div style="font-size: 13px; color: #4b5563;">{{ $dia['pedidos'] }} {{ $dia['pedidos'] === 1 ? 'pedido' : 'pedidos' }} · receita {{ $brl($dia['receita']) }} · margem {{ $margem($dia['lucro'], $dia['receita']) }}</div>
                    </td></tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td width="50%" style="padding-right: 5px; vertical-align: top;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px;">
                                <tr><td style="padding: 14px 16px;">
                                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #4b5563;">Lucro líquido do mês</div>
                                    <div style="font-size: 20px; font-weight: 800; color: {{ $cor($mes['lucro']) }};">{{ $brl($mes['lucro']) }}</div>
                                    <div style="font-size: 11px; color: #6b7280;">{{ $mes['pedidos'] }} pedidos · receita {{ $brl($mes['receita']) }}<br>margem {{ $margem($mes['lucro'], $mes['receita']) }}</div>
                                </td></tr>
                            </table>
                        </td>
                        <td width="50%" style="padding-left: 5px; vertical-align: top;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fff7ed; border: 1px solid #fed7aa; border-radius: 12px;">
                                <tr><td style="padding: 14px 16px;">
                                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #4b5563;">Impostos do mês a pagar</div>
                                    <div style="font-size: 20px; font-weight: 800; color: #c2410c;">{{ $brl($mes['impostos']) }}</div>
                                    <div style="font-size: 11px; color: #6b7280;">DAS · Simples Nacional {{ $aliquota }}%<br>vence em {{ $resultado['vencimento_das']->format('d/m/Y') }}</div>
                                </td></tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- De onde sai o lucro do dia --}}
    <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #111827;">Como fechou o dia</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; color: #374151; margin: 0 0 22px;">
        @foreach ([
            ['Receita (produtos − descontos)', $dia['receita'], false],
            ['Custo dos produtos', $dia['custo_produto'], true],
            ['Taxas dos canais'.($dia['taxas_estimadas'] > 0 ? ' *' : ''), $dia['taxas_canal'], true],
            ['Anúncios', $dia['anuncios'], true],
            ['Frete pago', $dia['frete'], true],
            ['Imposto (Simples '.$aliquota.'%)', $dia['impostos'], true],
        ] as [$rotulo, $valor, $desconta])
            <tr>
                <td style="padding: 5px 0; border-bottom: 1px solid #f1f2f4;">{{ $rotulo }}</td>
                <td style="padding: 5px 0; border-bottom: 1px solid #f1f2f4; text-align: right; white-space: nowrap; color: {{ $desconta && $valor > 0 ? '#b42318' : '#111827' }};">{{ $desconta && $valor > 0 ? '− ' : '' }}{{ $brl($valor) }}</td>
            </tr>
        @endforeach
        <tr>
            <td style="padding: 9px 0 0; font-weight: 800; color: #111827;">Lucro líquido</td>
            <td style="padding: 9px 0 0; text-align: right; font-weight: 800; color: {{ $cor($dia['lucro']) }};">{{ $brl($dia['lucro']) }}</td>
        </tr>
    </table>
    @if ($dia['taxas_estimadas'] > 0)
        <p style="margin: -14px 0 22px; font-size: 11px; color: #6b7280;">
            * Inclui {{ $brl($dia['taxas_estimadas']) }} estimados pela média do extrato ({{ implode(', ', $dia['canais_estimados']) }}), até a taxa real chegar.
        </p>
    @endif

    {{-- Mês dia a dia --}}
    <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #111827;">{{ ucfirst($resultado['data']->translatedFormat('F')) }} dia a dia</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 12px; color: #374151; border-collapse: collapse;">
        <tr style="color: #6b7280; text-transform: uppercase; font-size: 10px; letter-spacing: 0.04em;">
            <td style="padding: 4px 0; border-bottom: 2px solid #e5e7eb;">Dia</td>
            <td style="padding: 4px 0; border-bottom: 2px solid #e5e7eb; text-align: right;">Pedidos</td>
            <td style="padding: 4px 0; border-bottom: 2px solid #e5e7eb; text-align: right;">Receita</td>
            <td style="padding: 4px 0; border-bottom: 2px solid #e5e7eb; text-align: right;">Lucro</td>
            <td style="padding: 4px 0; border-bottom: 2px solid #e5e7eb; text-align: right;">Acumulado</td>
        </tr>
        @foreach (array_reverse($resultado['dias']) as $linha)
            @php($vazio = $linha['pedidos'] === 0)
            @php($largura = $vazio ? 0 : max(4, (int) round(abs((float) $linha['lucro']) / $maiorLucro * 100)))
            <tr style="{{ $vazio ? 'color: #9ca3af;' : '' }}">
                <td style="padding: 5px 0; border-bottom: 1px solid #f1f2f4; font-weight: {{ $vazio ? '400' : '700' }};">{{ $linha['data']->format('d/m') }}</td>
                <td style="padding: 5px 0; border-bottom: 1px solid #f1f2f4; text-align: right;">{{ $vazio ? '—' : $linha['pedidos'] }}</td>
                <td style="padding: 5px 0; border-bottom: 1px solid #f1f2f4; text-align: right; white-space: nowrap;">{{ $vazio ? '—' : $brl($linha['receita']) }}</td>
                <td style="padding: 5px 0 5px 8px; border-bottom: 1px solid #f1f2f4; text-align: right; white-space: nowrap; font-weight: 700; color: {{ $vazio ? '#9ca3af' : $cor($linha['lucro']) }};">
                    {{ $vazio ? '—' : $brl($linha['lucro']) }}
                    @unless ($vazio)
                        <div style="margin-top: 3px; height: 4px; border-radius: 4px; background-color: #f1f2f4;">
                            <div style="width: {{ $largura }}%; height: 4px; border-radius: 4px; margin-left: auto; background-color: {{ (float) $linha['lucro'] < 0 ? '#ef4444' : '#22c55e' }};"></div>
                        </div>
                    @endunless
                </td>
                <td style="padding: 5px 0; border-bottom: 1px solid #f1f2f4; text-align: right; white-space: nowrap; color: {{ $vazio ? '#9ca3af' : $cor($linha['acumulado']) }};">{{ $brl($linha['acumulado']) }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin: 18px 0 0; font-size: 11px; color: #9ca3af;">
        Lucro líquido = receita − custo dos produtos − taxas dos canais − anúncios − frete − imposto. Imposto estimado
        com a alíquota efetiva do Simples Nacional informada pelo contador ({{ $aliquota }}%); o valor oficial é o do DAS.
    </p>
@endsection
