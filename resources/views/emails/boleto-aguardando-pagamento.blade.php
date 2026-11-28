@extends('emails.layout')

@section('title', 'Seu boleto está pronto')
@section('preheader', 'Pague R$ '.number_format($valor, 2, ',', '.').' no boleto e seu pedido '.$order->numero.' segue para separação.')

@section('content')
    @php
        // Mesmo layout do pedido confirmado (28/11/2026): celular primeiro, sem o quadro laranja.
        $marca = $marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order);
        $cor = $marca['cor'];
        $rotulo = 'font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280;';
    @endphp

    @include('emails.partials.status-pedido', ['numero' => $order->numero, 'titulo' => 'Seu boleto está pronto!', 'cor' => $cor, 'passo' => 0])

    <p style="margin: 0 0 22px; font-size: 15px; line-height: 23px; color: #374151;">
        Falta só pagar para o pedido <strong style="color: {{ $cor }};">{{ $order->numero }}</strong> seguir para separação. Pague pelo app do seu banco
        com a linha digitável abaixo ou imprima o boleto.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 20px; background-color: #f8f9fb; border: 1px solid #eceef1; border-radius: 12px;">
        <tr>
            <td align="center" style="padding: 20px 18px; text-align: center; font-family: Arial, Helvetica, sans-serif;">
                <div style="{{ $rotulo }}">Valor a pagar</div>
                <div style="margin-top: 4px; font-size: 32px; line-height: 38px; font-weight: 800; color: #14202e;">R$ {{ number_format($valor, 2, ',', '.') }}</div>
                @if ($vencimento)
                    <div style="margin-top: 2px; font-size: 13px; line-height: 18px; color: #4b5563;">Vence em <strong>{{ $vencimento->format('d/m/Y') }}</strong></div>
                @endif
            </td>
        </tr>
    </table>

    @if ($linhaDigitavel)
        <div style="{{ $rotulo }} margin: 0 0 8px;">Linha digitável</div>
        <div style="padding: 12px 14px; border: 1px dashed #c4c9d0; border-radius: 10px; background-color: #f8f9fb; font-family: 'Courier New', monospace; font-size: 13px; line-height: 19px; color: #14202e; word-break: break-all;">{{ $linhaDigitavel }}</div>
        <p style="margin: 8px 0 0; font-size: 13px; line-height: 19px; color: #6b7280;">Copie os números acima e cole na opção "Pagar boleto" do seu banco.</p>
    @endif

    @if ($pdfUrl)
        @include('emails.partials.botao', ['url' => $pdfUrl, 'texto' => 'Abrir boleto PDF', 'cor' => $cor, 'largo' => true, 'margem' => '24px 0 12px'])
    @endif

    @include('emails.partials.botao', ['url' => $marca['pedido'], 'texto' => 'Ver meu pedido', 'cor' => '#0FB930', 'largo' => true, 'margem' => ($pdfUrl ? '0' : '24px').' 0 24px'])

    @if ($order->items->isNotEmpty())
        <div style="{{ $rotulo }} margin: 4px 0 2px;">Resumo do pedido</div>
        @include('emails.partials.itens-do-pedido', ['order' => $order])
        @include('emails.partials.resumo-valores', ['order' => $order])
    @endif

    <p style="margin: 24px 0 0; font-size: 12px; line-height: 18px; color: #9ca3af;">
        O banco leva até 3 dias úteis para confirmar o pagamento do boleto. Assim que cair, você recebe a confirmação por aqui.
        Se o boleto vencer sem pagamento, o pedido é cancelado. Se já pagou, pode ignorar este e-mail.
    </p>
@endsection
