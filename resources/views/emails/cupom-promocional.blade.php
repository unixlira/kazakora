@extends('emails.layout')

@section('title', $assunto)

@section('content')
    @foreach (preg_split('/\n\s*\n/', trim($mensagem)) as $paragrafo)
        <p style="margin: 0 0 16px;">{!! nl2br(e($paragrafo)) !!}</p>
    @endforeach

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 8px 0 20px; width: 100%;">
        <tr>
            <td align="center" style="border: 2px dashed #111111; border-radius: 10px; padding: 18px;">
                <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; letter-spacing: 2px; color: #555555; text-transform: uppercase;">Seu cupom</div>
                <div style="font-family: 'Courier New', monospace; font-size: 28px; font-weight: 700; letter-spacing: 3px; color: #111111; margin-top: 6px;">{{ $coupon->code }}</div>
                <div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #0a8a23; font-weight: 700; margin-top: 6px;">{{ $coupon->descricaoDesconto() }}</div>
                @if ($coupon->min_order_value)
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #555555; margin-top: 4px;">Em compras a partir de R$ {{ number_format((float) $coupon->min_order_value, 2, ',', '.') }}</div>
                @endif
                @if ($coupon->expires_at)
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #555555; margin-top: 4px;">Válido até {{ $coupon->expires_at->format('d/m/Y') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto 24px;">
        <tr>
            <td style="border-radius: 8px; background-color: #0FB930;">
                <a href="{{ $linkLoja }}" style="display: inline-block; padding: 14px 32px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 700; color: #ffffff; text-decoration: none; border-radius: 8px;">
                    Usar meu cupom agora
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 0; color: #8a8a8a; font-size: 12px;">
        Você recebeu este e-mail porque é cliente da KazaKora.
        <a href="{{ $linkSair }}" style="color: #8a8a8a;">Não quero mais receber promoções</a>.
    </p>
@endsection
