@extends('emails.layout')

@section('title', $titulo)
@section('preheader', 'Avalie sua compra em menos de um minuto e ganhe um cupom para a próxima.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '⭐', 'texto' => $titulo])

    @foreach (preg_split('/\n\s*\n/', trim($mensagem)) as $paragrafo)
        <p style="margin: 0 0 16px;">{!! nl2br(e($paragrafo)) !!}</p>
    @endforeach

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 4px 0 8px;">
        @foreach ($produtos as $produto)
            <tr>
                <td width="72" style="padding: 10px 12px 10px 0; border-bottom: 1px solid #eef0f3; vertical-align: middle;">
                    <a href="{{ $produto['link'] }}" target="_blank">
                        @if ($produto['imagem'])
                            <img src="{{ $produto['imagem'] }}" alt="" width="64" height="64" style="display: block; width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid #eef0f3;">
                        @else
                            <div style="width: 64px; height: 64px; border-radius: 10px; background-color: #f3f4f6;"></div>
                        @endif
                    </a>
                </td>
                <td style="padding: 10px 0; border-bottom: 1px solid #eef0f3; vertical-align: middle; font-size: 14px; color: #111827;">
                    {{ $produto['nome'] }}
                    <div style="margin-top: 4px; font-size: 18px; letter-spacing: 2px; color: #f5b301;">
                        <a href="{{ $produto['link'] }}" target="_blank" style="color: #f5b301; text-decoration: none;">★★★★★</a>
                    </div>
                </td>
                <td style="padding: 10px 0 10px 12px; border-bottom: 1px solid #eef0f3; vertical-align: middle; text-align: right; white-space: nowrap;">
                    <a href="{{ $produto['link'] }}" target="_blank" style="display: inline-block; padding: 9px 16px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #ffffff; background-color: #f27a2a; text-decoration: none; border-radius: 999px;">Avaliar</a>
                </td>
            </tr>
        @endforeach
    </table>

    @if (count($produtos) === 1)
        @include('emails.partials.botao', ['url' => $produtos[0]['link'], 'texto' => $botao, 'cor' => '#f27a2a'])
    @endif

    @if ($coupon)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 16px 0 8px; width: 100%;">
            <tr>
                <td align="center" style="border: 2px dashed #f27a2a; background-color: #fff7ed; border-radius: 12px; padding: 18px;">
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; letter-spacing: 2px; color: #555555; text-transform: uppercase;">Cupom para a sua próxima compra</div>
                    <div style="font-family: 'Courier New', monospace; font-size: 28px; font-weight: 700; letter-spacing: 3px; color: #111111; margin-top: 6px;">{{ $coupon->code }}</div>
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #0a8a23; font-weight: 700; margin-top: 6px;">{{ $coupon->descricaoDesconto() }}</div>
                    @if ($coupon->min_order_value)
                        <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #555555; margin-top: 4px;">Em compras a partir de R$ {{ number_format((float) $coupon->min_order_value, 2, ',', '.') }}</div>
                    @endif
                    @if ($coupon->expires_at)
                        <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #555555; margin-top: 4px;">Válido até {{ $coupon->expires_at->format('d/m/Y') }}, uso único</div>
                    @endif
                </td>
            </tr>
        </table>

        @include('emails.partials.botao', ['url' => $linkLoja, 'texto' => 'Usar meu cupom', 'cor' => '#0FB930'])
    @endif

    <p style="margin: 0; color: #8a8a8a; font-size: 12px;">
        Você recebeu este e-mail porque comprou na KazaKora.
        @if ($linkSair)
            <a href="{{ $linkSair }}" style="color: #8a8a8a;">Não quero mais receber promoções</a>.
        @endif
    </p>
@endsection
