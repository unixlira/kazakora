@extends('emails.layout')

@php
    $reais = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $grupos = [
        'adicionados' => ['Entraram agora', '#ecfdf5', '#a7f3d0'],
        'recusados' => ['Recusados pela Shopee', '#fef2f2', '#fecaca'],
        'pulados' => ['Ficaram de fora (regra da lista)', '#fffbeb', '#fde68a'],
    ];
@endphp

@section('title', 'Oferta Relâmpago da Shopee')
@section('preheader', 'Programação automática da Relâmpago dos próximos dias.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '⚡', 'texto' => 'Oferta Relâmpago da Shopee'])

    @foreach ($resultados as $r)
        <h3 style="margin: 24px 0 6px; font-size: 16px; color: #111827;">
            {{ \Illuminate\Support\Carbon::parse($r['dia'])->locale('pt_BR')->translatedFormat('l, d/m') }}
        </h3>
        <p style="margin: 0 0 10px; font-size: 13px; color: #374151;">
            @if ($r['erro'])
                <strong style="color: #b91c1c;">Erro:</strong> {{ $r['erro'] }}<br>
            @endif
            Relâmpago {{ $r['flash_sale_id'] ?? '—' }}{{ $r['criada'] ? ' (criada agora)' : '' }}:
            <strong>{{ (int) $r['ativos_na_shopee'] }} produto(s) ativos</strong> na leitura da Shopee,
            {{ count($r['ja_estavam']) }} já estavam.
        </p>

        @foreach ($grupos as $grupo => [$rotulo, $fundo, $borda])
            @if (! empty($r[$grupo]))
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 10px; border: 1px solid {{ $borda }}; background-color: {{ $fundo }}; border-radius: 12px;">
                    <tr><td style="padding: 12px 14px; font-size: 13px; color: #111827;">
                        <div style="font-weight: 800; margin-bottom: 6px;">{{ $rotulo }}</div>
                        @foreach ($r[$grupo] as $l)
                            <div style="margin: 0 0 4px;">
                                {{ $l['nome'] }}
                                @isset($l['preco'])
                                    · <strong>{{ $reais($l['preco']) }}</strong>{{ ! empty($l['ajustado']) ? ' (baixado para 1% abaixo do anúncio)' : '' }}
                                @endisset
                                @isset($l['estoque']) · {{ $l['estoque'] }} un. @endisset
                                @isset($l['lucro']) · lucro {{ $reais($l['lucro']) }}/un. @endisset
                                @if (! empty($l['motivo']))
                                    <div style="font-size: 12px; color: #6b7280;">{{ $l['motivo'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </td></tr>
                </table>
            @endif
        @endforeach
    @endforeach

    <p style="margin: 16px 0 0; font-size: 12px; color: #6b7280;">
        Lucro estimado: preço − taxa da Shopee pela faixa − Simples − custo, sem ADS. Lista: <code>shopee:relampago-itens</code>.
    </p>
@endsection
