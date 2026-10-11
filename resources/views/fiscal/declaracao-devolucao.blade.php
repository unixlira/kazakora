@php
    $doc = preg_replace('/\D/', '', (string) $order->buyer_document);
    $docFormatado = strlen($doc) === 11
        ? substr($doc, 0, 3).'.'.substr($doc, 3, 3).'.'.substr($doc, 6, 3).'-'.substr($doc, 9, 2)
        : ($doc ?: '____________________');
    $cnpj = preg_replace('/\D/', '', (string) $company?->cnpj);
    $cnpjFormatado = strlen($cnpj) === 14
        ? substr($cnpj, 0, 2).'.'.substr($cnpj, 2, 3).'.'.substr($cnpj, 5, 3).'/'.substr($cnpj, 8, 4).'-'.substr($cnpj, 12, 2)
        : $company?->cnpj;
    $moeda = fn ($valor) => 'R$ '.number_format((float) $valor, 2, ',', '.');
    $total = $itens->sum(fn ($item) => $item['preco'] * $item['devolver']);
    $endereco = trim("{$order->shipping_street}, {$order->shipping_number}".($order->shipping_complement ? " - {$order->shipping_complement}" : '')." - {$order->shipping_neighborhood} - {$order->shipping_city}/{$order->shipping_state} - CEP {$order->shipping_zip}");
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Declaração de devolução - NF-e {{ $invoice->numero }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; background: #fff; margin: 0; padding: 32px; font-size: 13px; line-height: 1.5; }
        .folha { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 18px; text-align: center; margin: 0 0 24px; text-transform: uppercase; letter-spacing: .5px; }
        p { margin: 0 0 12px; text-align: justify; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
        th, td { border: 1px solid #555; padding: 6px 8px; text-align: left; }
        th { background: #eee; font-size: 12px; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .linha { border-bottom: 1px solid #111; min-height: 20px; margin-bottom: 4px; }
        .assinatura { margin-top: 56px; text-align: center; }
        .assinatura .linha { width: 70%; margin: 0 auto 4px; }
        .acoes { text-align: center; margin-bottom: 24px; }
        .acoes button { font-size: 14px; padding: 8px 18px; cursor: pointer; }
        .chave { font-family: monospace; font-size: 12px; word-break: break-all; }
        @media print { .acoes { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="folha">
    <div class="acoes"><button type="button" onclick="window.print()">Imprimir / salvar em PDF</button></div>

    <h1>Declaração de devolução de mercadoria</h1>

    <p>
        Eu, <strong>{{ $order->shipping_name }}</strong>, inscrito(a) no CPF/CNPJ sob o nº <strong>{{ $docFormatado }}</strong>,
        residente em {{ $endereco }}, declaro para os devidos fins que estou devolvendo à empresa
        <strong>{{ $company?->razao_social }}</strong>, CNPJ <strong>{{ $cnpjFormatado }}</strong>, as mercadorias abaixo,
        recebidas por meio da Nota Fiscal Eletrônica nº <strong>{{ $invoice->numero }}</strong>, série <strong>{{ $invoice->serie }}</strong>,
        emitida em {{ $invoice->autorizada_em?->format('d/m/Y') ?? '___/___/______' }}, chave de acesso
        <span class="chave">{{ $invoice->chave_acesso }}</span>.
    </p>

    <p>Declaro ainda que não sou contribuinte do ICMS e, por isso, não emito nota fiscal de devolução.</p>

    <table>
        <thead>
            <tr><th>Produto</th><th class="num">Qtd.</th><th class="num">Valor unit.</th><th class="num">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($itens as $item)
                <tr>
                    <td>{{ $item['nome'] }}</td>
                    <td class="num">{{ $item['devolver'] }}</td>
                    <td class="num">{{ $moeda($item['preco']) }}</td>
                    <td class="num">{{ $moeda($item['preco'] * $item['devolver']) }}</td>
                </tr>
            @endforeach
            <tr><th colspan="3" class="num">Total devolvido</th><th class="num">{{ $moeda($total) }}</th></tr>
        </tbody>
    </table>

    <p><strong>Motivo da devolução:</strong></p>
    @if ($motivo !== '')
        <p>{{ $motivo }}</p>
    @else
        <div class="linha"></div><div class="linha"></div>
    @endif

    <p style="margin-top: 32px;">{{ $order->shipping_city ?: '____________________' }}, ____ de ____________________ de ________.</p>

    <div class="assinatura">
        <div class="linha"></div>
        {{ $order->shipping_name }}<br>
        CPF/CNPJ {{ $docFormatado }}
    </div>
</div>
</body>
</html>
