@extends('emails.layout-interno')

@section('title', 'Fechamento fiscal')

@php
    $td = 'padding: 8px 10px; border: 1px solid #e2e8f0; font-size: 13px;';
    $th = $td.' background-color: #f1f5f9; font-weight: 600; color: #1b3a5c; text-align: left;';
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
@endphp

@section('content')
    <h1 style="margin: 0 0 8px; font-family: Georgia, 'Times New Roman', serif; font-size: 22px; font-weight: 600; color: #1b3a5c;">
        Fechamento fiscal de {{ $r['mes_extenso'] }}
    </h1>
    <p style="margin: 0 0 16px; color: #526075; font-size: 13px;">
        Gerado em {{ $r['gerado_em'] }}. Em anexo: planilha com todas as notas{{ $zipAnexado ? ' e o ZIP com os XMLs (notas, cancelamentos e inutilizações)' : '' }}.
        @unless ($zipAnexado)
            O ZIP com os XMLs ficou grande demais para anexar: baixe em <a href="{{ $link }}">{{ $link }}</a>.
        @endunless
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 20px; border-collapse: collapse;">
        <tr><td style="{{ $td }}">Notas autorizadas</td><td style="{{ $td }} text-align: right; font-weight: 600;">{{ $r['totais']['autorizadas'] }} ({{ $brl($r['totais']['valor_autorizado']) }})</td></tr>
        <tr><td style="{{ $td }}">Canceladas</td><td style="{{ $td }} text-align: right; font-weight: 600;">{{ $r['totais']['canceladas'] }}</td></tr>
        <tr><td style="{{ $td }}">Canceladas depois de 24h (fora do prazo)</td><td style="{{ $td }} text-align: right; font-weight: 600; color: {{ $r['totais']['canceladas_fora_do_prazo'] ? '#b91c1c' : '#1b3a5c' }};">{{ $r['totais']['canceladas_fora_do_prazo'] }} — multa estimada {{ $brl($r['totais']['multa_estimada']) }}</td></tr>
        <tr><td style="{{ $td }}">Notas de devolução</td><td style="{{ $td }} text-align: right; font-weight: 600;">{{ $r['totais']['devolucoes'] }}</td></tr>
        <tr><td style="{{ $td }}">Notas sem XML no sistema</td><td style="{{ $td }} text-align: right; font-weight: 600; color: {{ $r['totais']['sem_xml'] ? '#b91c1c' : '#1b3a5c' }};">{{ $r['totais']['sem_xml'] }}</td></tr>
        <tr><td style="{{ $td }}">UFESP {{ $r['ufesp']['ano'] }}</td><td style="{{ $td }} text-align: right;">{{ $brl($r['ufesp']['valor']) }}{{ !empty($r['ufesp']['base_legal']) ? ' ('.$r['ufesp']['base_legal'].')' : '' }}</td></tr>
    </table>

    <h2 style="margin: 0 0 8px; font-size: 16px; color: #1b3a5c;">Séries usadas no mês</h2>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 20px; border-collapse: collapse;">
        <tr><th style="{{ $th }}">Série</th><th style="{{ $th }}">Emissor</th><th style="{{ $th }}">Números</th><th style="{{ $th }}">Autorizadas</th><th style="{{ $th }}">Canceladas</th></tr>
        @foreach ($r['series'] as $s)
            <tr>
                <td style="{{ $td }}">{{ $s['serie'] }}</td>
                <td style="{{ $td }}">{{ $s['emissor'] }}</td>
                <td style="{{ $td }}">{{ $s['primeiro'] }} a {{ $s['ultimo'] }}</td>
                <td style="{{ $td }}">{{ $s['autorizadas'] }} ({{ $brl($s['valor']) }})</td>
                <td style="{{ $td }}">{{ $s['canceladas'] }}</td>
            </tr>
        @endforeach
    </table>

    @if (count($r['canceladas']))
        <h2 style="margin: 0 0 8px; font-size: 16px; color: #1b3a5c;">Notas canceladas</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 20px; border-collapse: collapse;">
            <tr><th style="{{ $th }}">Série/Nº</th><th style="{{ $th }}">Autorizada</th><th style="{{ $th }}">Cancelada</th><th style="{{ $th }}">Prazo</th><th style="{{ $th }}">Valor</th></tr>
            @foreach ($r['canceladas'] as $n)
                <tr>
                    <td style="{{ $td }}">{{ $n['serie'] }}/{{ $n['numero'] }}</td>
                    <td style="{{ $td }}">{{ $n['autorizada_em'] }}</td>
                    <td style="{{ $td }}">{{ $n['cancelada_em'] ?? 'sem data' }}</td>
                    <td style="{{ $td }} color: {{ $n['fora_do_prazo'] ? '#b91c1c' : '#15803d' }};">{{ $n['fora_do_prazo'] ? 'Fora do prazo'.($n['horas_ate_cancelar'] !== null ? ' ('.$n['horas_ate_cancelar'].'h)' : '') : ($n['horas_ate_cancelar'] !== null ? 'No prazo ('.$n['horas_ate_cancelar'].'h)' : 'Sem data de cancelamento') }}</td>
                    <td style="{{ $td }}">{{ $brl($n['valor']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (count($r['devolucoes']))
        <h2 style="margin: 0 0 8px; font-size: 16px; color: #1b3a5c;">Notas de devolução</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 20px; border-collapse: collapse;">
            <tr><th style="{{ $th }}">Série/Nº</th><th style="{{ $th }}">Tipo</th><th style="{{ $th }}">Situação</th><th style="{{ $th }}">Valor</th></tr>
            @foreach ($r['devolucoes'] as $n)
                <tr>
                    <td style="{{ $td }}">{{ $n['serie'] }}/{{ $n['numero'] }}</td>
                    <td style="{{ $td }}">{{ $n['operacao'] === 'sales_return' ? 'Devolução de venda (entrada)' : 'Devolução de compra (saída)' }}</td>
                    <td style="{{ $td }}">{{ $n['situacao'] }}</td>
                    <td style="{{ $td }}">{{ $brl($n['valor']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (count($r['duplicidades']) || count($r['inutilizacoes']) || count($r['buracos']))
        <h2 style="margin: 0 0 8px; font-size: 16px; color: #1b3a5c;">Numeração (série {{ $r['serie_kazakora'] }})</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin: 0 0 20px; border-collapse: collapse;">
            @foreach ($r['inutilizacoes'] as $o)
                <tr><td style="{{ $td }}">Inutilizados {{ $o['inicio'] }}{{ $o['fim'] !== $o['inicio'] ? ' a '.$o['fim'] : '' }}</td><td style="{{ $td }}">Protocolo {{ $o['protocolo'] }} — {{ $o['motivo'] }}</td></tr>
            @endforeach
            @foreach ($r['duplicidades'] as $o)
                <tr><td style="{{ $td }}">Nº {{ $o['inicio'] }} já existia na SEFAZ</td><td style="{{ $td }}">Pulado no pedido #{{ $o['pedido'] }} — falta o XML dessa nota</td></tr>
            @endforeach
            @foreach ($r['buracos'] as $b)
                <tr><td style="{{ $td }} color: #b91c1c;">Sem nota: {{ $b['inicio'] }}{{ $b['fim'] !== $b['inicio'] ? ' a '.$b['fim'] : '' }} ({{ $b['quantidade'] }})</td><td style="{{ $td }}">{{ $b['motivo'] }} — ainda não inutilizado</td></tr>
            @endforeach
        </table>
    @endif

    <p style="margin: 0; color: #526075; font-size: 13px;">
        Detalhes e ações (inutilizar, conferir duplicidades): <a href="{{ $link }}">{{ $link }}</a>
    </p>
@endsection
