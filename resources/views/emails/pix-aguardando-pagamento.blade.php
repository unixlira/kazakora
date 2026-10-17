@extends('emails.layout')

@section('title', 'Seu Pix está pronto')
@section('preheader', 'Pague R$ '.number_format($valor, 2, ',', '.').' com o Pix e seu pedido '.$order->numero.' segue para separação.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '⚡', 'texto' => 'Seu Pix está pronto!'])

    <p style="margin: 0 0 18px; text-align: center; color: #374151;">
        Falta só pagar para o pedido <strong style="color: #F27A2A;">{{ $order->numero }}</strong> seguir para separação. Abra o app do seu banco
        e escaneie o QR Code ou use o Pix copia e cola.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 14px;">
        <tr>
            <td style="padding: 18px; text-align: center;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #166534;">Valor a pagar</div>
                <div style="font-size: 32px; font-weight: 800; color: #0a8a23;">R$ {{ number_format($valor, 2, ',', '.') }}</div>
                @if ($validade)
                    <div style="font-size: 12px; color: #4b5563;">Pague até {{ $validade->format('d/m/Y \à\s H:i') }}</div>
                @endif

                @if ($qrPng && isset($message))
                    <img src="{{ $message->embedData($qrPng, 'qrcode-pix.png', 'image/png') }}" alt="QR Code do Pix" width="200" height="200"
                        style="display: block; margin: 16px auto 0; width: 200px; height: 200px; border: 8px solid #ffffff; border-radius: 10px;">
                @endif
            </td>
        </tr>
    </table>

    <p style="margin: 0 0 6px; font-size: 13px; font-weight: 700; color: #111827;">Pix copia e cola</p>
    <div style="padding: 12px 14px; border: 1px dashed #9ca3af; border-radius: 10px; background-color: #f9fafb; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.5; color: #111827; word-break: break-all;">{{ $copiaECola }}</div>
    <p style="margin: 6px 0 0; font-size: 12px; color: #6b7280;">Copie o código acima e cole na opção "Pix copia e cola" do seu banco.</p>

    @include('emails.partials.botao', ['url' => ($marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order))['pedido'], 'texto' => 'Ver meu pedido', 'cor' => '#0FB930'])

    <p style="margin: 0; text-align: center; font-size: 12px; color: #9ca3af;">
        Assim que o pagamento cair, você recebe a confirmação por aqui. Se já pagou, pode ignorar este e-mail.
    </p>
@endsection
