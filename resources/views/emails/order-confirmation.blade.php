@extends('emails.layout')

@section('title', "Pedido {$order->numero} confirmado")
@section('preheader', "Seu pedido {$order->numero} foi confirmado e já está sendo preparado.")

@section('content')
    @php
        // Layout refeito em 28/11/2026 (pedido do Lira: no celular o quadro laranja
        // e a lista de produtos ficavam desalinhados). Celular primeiro: tudo
        // alinhado à esquerda, itens em linhas iguais e a cor da marca só no que
        // importa (faixa de andamento e botão).
        $marca = $marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order);
        $cor = $marca['cor'];
        $invoiceAuthorized = $order->invoice?->status === \App\Modules\Fiscal\Models\Invoice::STATUS_AUTHORIZED;
        $primeiroNome = strtok((string) $order->shipping_name, ' ') ?: $order->shipping_name;
        $pagamento = $order->payments->whereNull('substituido_em')->sortByDesc('id')->first() ?? $order->payments->sortByDesc('id')->first();
        $formaPagamento = match ($pagamento?->method_type) {
            \App\Modules\Checkout\Models\Payment::METHOD_PIX => 'Pix',
            \App\Modules\Checkout\Models\Payment::METHOD_CARD => 'Cartão de crédito',
            \App\Modules\Checkout\Models\Payment::METHOD_BOLETO => 'Boleto bancário',
            default => null,
        };
        $rotulo = 'font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280;';
    @endphp

    @include('emails.partials.status-pedido', ['numero' => $order->numero, 'titulo' => 'Pedido confirmado!', 'cor' => $cor, 'passo' => 1])

    <p style="margin: 0 0 22px; font-size: 15px; line-height: 23px; color: #374151;">
        Olá, {{ $primeiroNome }}! Recebemos seu pagamento e seu pedido já está sendo preparado com carinho.
    </p>

    @if ($notaAnexada ?? $invoiceAuthorized)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px; background-color: #f3fbf5; border: 1px solid #cdeed6; border-radius: 12px;">
            <tr>
                <td width="22" valign="top" style="width: 22px; padding: 14px 0 14px 16px; font-size: 18px; line-height: 20px;">&#128206;</td>
                <td valign="top" style="padding: 14px 16px 14px 10px; font-family: Arial, Helvetica, sans-serif; color: #065f46;">
                    <div style="font-size: 14px; line-height: 20px; font-weight: 700;">A Nota Fiscal do seu pedido está em anexo</div>
                    <div style="font-size: 13px; line-height: 19px; color: #2f6b52;">Enviamos a NF-e (DANFE) em PDF anexada a este e-mail. É só abrir o anexo para ver, salvar ou imprimir.</div>
                    <div style="font-size: 11px; line-height: 16px; color: #4b7a63; word-break: break-all; margin-top: 6px;">Chave de acesso: {{ $order->invoice->chave_acesso }}</div>
                </td>
            </tr>
        </table>
    @endif

    <div style="{{ $rotulo }} margin: 0 0 2px;">Itens do pedido</div>
    @include('emails.partials.itens-do-pedido', ['order' => $order, 'brindes' => $brindes ?? []])
    @include('emails.partials.resumo-valores', ['order' => $order])

    @include('emails.partials.botao', ['url' => $marca['pedido'], 'texto' => 'Acompanhar meu pedido', 'cor' => $cor, 'largo' => true, 'margem' => '24px 0 28px'])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top: 1px solid #eceef1;">
        <tr>
            <td class="coluna-celular" width="{{ $formaPagamento ? '60%' : '100%' }}" valign="top" style="padding: 20px {{ $formaPagamento ? '16px' : '0' }} 0 0; font-family: Arial, Helvetica, sans-serif;">
                <div style="{{ $rotulo }} margin: 0 0 6px;">Endereço de entrega</div>
                <div style="font-size: 14px; line-height: 21px; color: #374151;">
                    <strong style="color: #14202e;">{{ $order->shipping_name }}</strong><br>
                    {{ $order->shipping_street }}, {{ $order->shipping_number }}@if ($order->shipping_complement) - {{ $order->shipping_complement }}@endif<br>
                    {{ $order->shipping_neighborhood }} - {{ $order->shipping_city }}/{{ $order->shipping_state }}<br>
                    CEP {{ $order->shipping_zip }}
                </div>
            </td>
            @if ($formaPagamento)
                <td class="coluna-celular" width="40%" valign="top" style="padding: 20px 0 0; font-family: Arial, Helvetica, sans-serif;">
                    <div style="{{ $rotulo }} margin: 0 0 6px;">Pagamento</div>
                    <div style="font-size: 14px; line-height: 21px; color: #374151;">
                        <strong style="color: #14202e;">{{ $formaPagamento }}</strong><br>
                        R$ {{ number_format((float) $order->total, 2, ',', '.') }} &middot; aprovado
                    </div>
                </td>
            @endif
        </tr>
    </table>

    {{-- Presente embaixo do endereço (pedido do Lira 2026-10-21). Desde 28/11/2026 o texto ocupa a largura toda e cada livro vira uma linha com o link à direita. --}}
    @if (! empty($brindes) && $brindes->isNotEmpty())
        @php
            // 24/11/2026: anexa os que cabem no limite; todos têm o link.
            $qtdAnexados = count($idsAnexados ?? []);
            $todosAnexados = ! empty($livrosAnexados) && $qtdAnexados >= $brindes->count();
        @endphp
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0 0; background-color: #f8f9fb; border: 1px solid #eceef1; border-radius: 12px;">
            <tr>
                <td style="padding: 18px 18px 6px; font-family: Arial, Helvetica, sans-serif;">
                    <div style="font-size: 16px; line-height: 22px; font-weight: 800; color: #14202e; margin: 0 0 6px;">&#127873; Um presente para você!</div>
                    <div style="font-size: 13px; line-height: 20px; color: #4b5563;">
                        Como agradecimento pela sua compra, separamos {{ $brindes->count() === 1 ? 'um livro digital' : $brindes->count().' livros digitais' }} de presente.
                        {{ $todosAnexados ? ($brindes->count() === 1 ? 'Ele está anexado a este e-mail em PDF e também' : 'Estão anexados a este e-mail em PDF e também') : (! empty($livrosAnexados) ? ($qtdAnexados === 1 ? 'Um deles está anexado a este e-mail em PDF (marcado abaixo) e todos' : $qtdAnexados.' deles estão anexados a este e-mail em PDF (marcados abaixo) e todos') : ($brindes->count() === 1 ? 'Ele' : 'Eles')) }} {{ $brindes->count() === 1 ? 'pode ser baixado' : 'podem ser baixados' }} no link abaixo, que fica guardado na página do seu pedido. Cada link é exclusivo do seu pedido e vale para um download, então guarde o PDF depois de baixar:
                    </div>
                </td>
            </tr>
            <tr>
                <td style="padding: 4px 18px 10px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        @foreach ($brindes as $livro)
                            @php $bordaLivro = $loop->first ? '' : 'border-top: 1px solid #eceef1;'; @endphp
                            <tr>
                                <td width="36" valign="middle" style="width: 36px; padding: 10px 12px 10px 0; {{ $bordaLivro }}">
                                    @if ($livro->capa_url)
                                        <img src="{{ $livro->capa_url }}" alt="" width="36" height="48" style="display: block; width: 36px; height: 48px; object-fit: cover; border-radius: 4px; border: 1px solid #e5e7eb;">
                                    @else
                                        <div style="width: 36px; height: 48px; line-height: 48px; text-align: center; font-size: 20px; border-radius: 4px; background-color: #ffffff; border: 1px solid #e5e7eb;">&#128216;</div>
                                    @endif
                                </td>
                                <td valign="middle" style="padding: 10px 12px 10px 0; {{ $bordaLivro }} font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 19px; font-weight: 700; color: #14202e;">
                                    {{ $livro->titulo }}@if (! empty($livrosAnexados) && ! $todosAnexados && in_array($livro->id, $idsAnexados ?? [], true))<div style="font-size: 12px; font-weight: 400; color: #6b7280;">(em anexo)</div>@endif
                                </td>
                                <td valign="middle" align="right" style="padding: 10px 0; {{ $bordaLivro }} text-align: right; white-space: nowrap;">
                                    <a href="{{ $livro->urlDoPedido($order) }}" target="_blank" style="display: inline-block; padding: 8px 14px; border: 1px solid {{ $cor }}; border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 16px; font-weight: 700; color: {{ $cor }}; text-decoration: none;">Baixar PDF</a>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endif
@endsection
