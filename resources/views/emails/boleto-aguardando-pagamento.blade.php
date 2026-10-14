@extends('emails.layout')

@section('title', 'Seu boleto está pronto')
@section('preheader', 'Pague R$ '.number_format($valor, 2, ',', '.').' no boleto e seu pedido '.$order->numero.' segue para separação.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '🧾', 'texto' => 'Seu boleto está pronto!'])

    <p style="margin: 0 0 18px; text-align: center; color: #374151;">
        Falta só pagar para o pedido <strong style="color: #F27A2A;">{{ $order->numero }}</strong> seguir para separação. Pague pelo app do seu banco
        com a linha digitável abaixo ou imprima o boleto.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; background-color: #fff7ed; border: 1px solid #fed7aa; border-radius: 14px;">
        <tr>
            <td style="padding: 18px; text-align: center;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #9a3412;">Valor a pagar</div>
                <div style="font-size: 32px; font-weight: 800; color: #111827;">R$ {{ number_format($valor, 2, ',', '.') }}</div>
                @if ($vencimento)
                    <div style="font-size: 13px; color: #4b5563;">Vence em <strong>{{ $vencimento->format('d/m/Y') }}</strong></div>
                @endif
            </td>
        </tr>
    </table>

    @if ($linhaDigitavel)
        <p style="margin: 0 0 6px; font-size: 13px; font-weight: 700; color: #111827;">Linha digitável</p>
        <div style="padding: 12px 14px; border: 1px dashed #9ca3af; border-radius: 10px; background-color: #f9fafb; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.5; color: #111827; word-break: break-all;">{{ $linhaDigitavel }}</div>
        <p style="margin: 6px 0 0; font-size: 12px; color: #6b7280;">Copie os números acima e cole na opção "Pagar boleto" do seu banco.</p>
    @endif

    @if ($pdfUrl)
        @include('emails.partials.botao', ['url' => $pdfUrl, 'texto' => 'Abrir o boleto (PDF)', 'cor' => '#F27A2A'])
    @endif

    @include('emails.partials.botao', ['url' => route('pedidos.meus'), 'texto' => 'Ver meu pedido', 'cor' => '#0FB930'])

    <p style="margin: 0; text-align: center; font-size: 12px; color: #9ca3af;">
        O banco leva até 3 dias úteis para confirmar o pagamento do boleto. Assim que cair, você recebe a confirmação por aqui.
        Se o boleto vencer sem pagamento, o pedido é cancelado. Se já pagou, pode ignorar este e-mail.
    </p>
@endsection
