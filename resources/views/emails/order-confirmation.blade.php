@extends('emails.layout')

@section('title', "Pedido {$order->numero} confirmado")
@section('preheader', "Seu pedido {$order->numero} foi confirmado e já está sendo preparado.")

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

    @include('emails.partials.titulo', ['icone' => '🎉', 'texto' => "Pedido {$order->numero} confirmado!"])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        Olá, {{ $order->shipping_name }}! Recebemos seu pagamento e seu pedido já está sendo preparado com carinho.
    </p>

    @if ($notaAnexada ?? $invoiceAuthorized)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 20px; background-color: #ecfdf3; border: 2px solid #0FB930; border-radius: 10px;">
            <tr>
                <td style="padding: 16px 18px; color: #065f46;">
                    <div style="font-size: 16px; font-weight: 800; margin: 0 0 4px;">&#128206; A Nota Fiscal do seu pedido está em anexo</div>
                    <div style="font-size: 13px;">Enviamos a NF-e (DANFE) em PDF anexada a este e-mail. É só abrir o anexo para ver, salvar ou imprimir.</div>
                    <div style="font-size: 11px; color: #4b7a63; word-break: break-all; margin-top: 6px;">Chave de acesso: {{ $order->invoice->chave_acesso }}</div>
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

    @include('emails.partials.botao', ['url' => ($marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order))['pedido'], 'texto' => 'Acompanhar meu pedido', 'cor' => '#F27A2A'])

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

    {{-- Presente embaixo do endereço (pedido do Lira 2026-10-21), com as capas dos livros em miniatura. --}}
    @if (! empty($brindes) && $brindes->isNotEmpty())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 12px 0 0; background-color: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px;">
            <tr>
                {{-- Miniatura dos livros à direita da lista; no celular desce para baixo do texto (pedido 2026-10-21). --}}
                <td class="coluna-celular" valign="middle" style="padding: 14px 18px; font-size: 13px; color: #7c2d12;">
                    <strong>&#127873; Um presente para você!</strong> Como agradecimento pela sua compra, separamos {{ $brindes->count() === 1 ? 'um livro digital' : $brindes->count().' livros digitais' }} de presente. {{ ! empty($livrosAnexados) ? ($brindes->count() === 1 ? 'Ele está anexado a este e-mail em PDF e também' : 'Estão anexados a este e-mail em PDF e também') : ($brindes->count() === 1 ? 'Ele' : 'Eles') }} {{ $brindes->count() === 1 ? 'pode ser baixado' : 'podem ser baixados' }} no link abaixo, que fica guardado na página do seu pedido. Cada link é exclusivo do seu pedido e vale para um download, então guarde o PDF depois de baixar:
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 10px 0 0;">
                        @foreach ($brindes as $livro)
                            <tr>
                                <td style="padding: 0 10px 8px 0; vertical-align: middle;">
                                    @if ($livro->capa_url)
                                        <img src="{{ $livro->capa_url }}" alt="" width="36" height="48" style="display: block; width: 36px; height: 48px; object-fit: cover; border-radius: 4px; border: 1px solid #fed7aa;">
                                    @endif
                                </td>
                                <td style="padding: 0 0 8px; vertical-align: middle; font-size: 13px; color: #7c2d12;">{{ $livro->titulo }}: <a href="{{ $livro->urlDoPedido($order) }}" style="color: #c2450c; font-weight: 700;">baixar PDF</a></td>
                            </tr>
                        @endforeach
                    </table>
                </td>
                @if (! empty($livrosImagem))
                    <td class="coluna-celular livros-miniatura" width="150" valign="middle" align="center" style="width: 150px; padding: 14px 18px 14px 0; text-align: center;">
                        <img src="{{ $livrosImagem }}" alt="Livros digitais de presente: {{ $brindes->pluck('titulo')->join(', ') }}" width="150" style="display: block; width: 150px; max-width: 100%; height: auto; margin: 0 auto; border: 1px solid #fed7aa; border-radius: 8px; background-color: #ffffff;">
                        <div style="margin-top: 4px; font-size: 11px; font-weight: 700; line-height: 1.3; color: #c2450c;">+ {{ $brindes->count() === 1 ? '1 livro digital GRÁTIS' : $brindes->count().' livros digitais GRÁTIS' }}</div>
                    </td>
                @endif
            </tr>
        </table>
    @endif
@endsection
