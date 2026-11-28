@extends('emails.layout')

@section('title', 'Seu Pix está pronto')
@section('preheader', 'Pague R$ '.number_format($valor, 2, ',', '.').' com o Pix e seu pedido '.$order->numero.' segue para separação.')

@section('content')
    @php
        // Mesmo layout do pedido confirmado (28/11/2026): celular primeiro, alinhado à esquerda.
        $marca = $marcaEmail ?? \App\Support\MarcaDoEmail::kazakora($order);
        $cor = $marca['cor'];
        $rotulo = 'font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280;';
    @endphp

    @include('emails.partials.status-pedido', ['numero' => $order->numero, 'titulo' => 'Seu Pix está pronto!', 'cor' => $cor, 'passo' => 0])

    <p style="margin: 0 0 22px; font-size: 15px; line-height: 23px; color: #374151;">
        Falta só pagar para o pedido <strong style="color: {{ $cor }};">{{ $order->numero }}</strong> seguir para separação. Abra o app do seu banco
        e escaneie o QR Code ou use o Pix copia e cola.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 20px; background-color: #f8f9fb; border: 1px solid #eceef1; border-radius: 12px;">
        <tr>
            <td align="center" style="padding: 20px 18px; text-align: center; font-family: Arial, Helvetica, sans-serif;">
                <div style="{{ $rotulo }}">Valor a pagar</div>
                <div style="margin-top: 4px; font-size: 32px; line-height: 38px; font-weight: 800; color: #0a8a23;">R$ {{ number_format($valor, 2, ',', '.') }}</div>
                @if ($validade)
                    <div style="margin-top: 2px; font-size: 13px; line-height: 18px; color: #4b5563;">Pague até {{ $validade->format('d/m/Y \à\s H:i') }}</div>
                @endif

                @if ($qrPng && isset($message))
                    <img src="{{ $message->embedData($qrPng, 'qrcode-pix.png', 'image/png') }}" alt="QR Code do Pix" width="200" height="200"
                        style="display: block; margin: 16px auto 0; width: 200px; height: 200px; border: 8px solid #ffffff; border-radius: 10px;">
                @endif
            </td>
        </tr>
    </table>

    <div style="{{ $rotulo }} margin: 0 0 8px;">Pix copia e cola</div>
    <div style="padding: 12px 14px; border: 1px dashed #c4c9d0; border-radius: 10px; background-color: #f8f9fb; font-family: 'Courier New', monospace; font-size: 12px; line-height: 18px; color: #14202e; word-break: break-all;">{{ $copiaECola }}</div>
    <p style="margin: 8px 0 0; font-size: 13px; line-height: 19px; color: #6b7280;">Copie o código acima e cole na opção "Pix copia e cola" do seu banco.</p>

    @include('emails.partials.botao', ['url' => $marca['pedido'], 'texto' => 'Ver meu pedido', 'cor' => '#0FB930', 'largo' => true])

    @if ($order->items->isNotEmpty())
        <div style="{{ $rotulo }} margin: 4px 0 2px;">Resumo do pedido</div>
        @include('emails.partials.itens-do-pedido', ['order' => $order])
        @include('emails.partials.resumo-valores', ['order' => $order])
    @endif

    <p style="margin: 24px 0 0; font-size: 12px; line-height: 18px; color: #9ca3af;">
        Assim que o pagamento cair, você recebe a confirmação por aqui. Se já pagou, pode ignorar este e-mail.
    </p>
@endsection
