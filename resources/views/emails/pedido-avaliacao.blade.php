@extends('emails.layout')

@section('title', $titulo)
@section('preheader', 'Avalie sua compra em menos de um minuto e ganhe '.($desconto ?: 'um cupom').' na próxima.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '⭐', 'texto' => $titulo])

    @foreach (preg_split('/\n\s*\n/', trim($mensagem)) as $paragrafo)
        <p style="margin: 0 0 16px;">{!! nl2br(e($paragrafo)) !!}</p>
    @endforeach

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 4px 0 8px;">
        @foreach ($produtos as $produto)
            <tr>
                <td width="72" style="padding: 10px 12px 10px 0; border-bottom: 1px solid #eef0f3; vertical-align: middle;">
                    <a href="{{ $link }}" target="_blank">
                        @if ($produto['imagem'])
                            <img src="{{ $produto['imagem'] }}" alt="" width="64" height="64" style="display: block; width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid #eef0f3;">
                        @else
                            <div style="width: 64px; height: 64px; border-radius: 10px; background-color: #f3f4f6;"></div>
                        @endif
                    </a>
                </td>
                <td style="padding: 10px 0; border-bottom: 1px solid #eef0f3; vertical-align: middle; font-size: 14px; color: #111827;">
                    {{ $produto['nome'] }}
                    <div style="margin-top: 4px; font-size: 22px; line-height: 1; color: #f5b301;">
                        @foreach (range(1, 5) as $nota)
                            <a href="{{ $linkNota($produto['id'], $nota) }}" target="_blank" title="{{ $nota }} {{ $nota === 1 ? 'estrela' : 'estrelas' }}" style="color: #f5b301; text-decoration: none; padding-right: 2px;">★</a>
                        @endforeach
                    </div>
                </td>
                <td style="padding: 10px 0 10px 12px; border-bottom: 1px solid #eef0f3; vertical-align: middle; text-align: right; white-space: nowrap;">
                    <a href="{{ $link }}" target="_blank" style="display: inline-block; padding: 9px 16px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #ffffff; background-color: #f27a2a; text-decoration: none; border-radius: 999px;">Avaliar</a>
                </td>
            </tr>
        @endforeach
    </table>

    @include('emails.partials.botao', ['url' => $link, 'texto' => $botao, 'cor' => '#f27a2a'])

    @if ($tabela)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 16px 0 8px; width: 100%;">
            <tr>
                <td align="center" style="border: 2px dashed #f27a2a; background-color: #fff7ed; border-radius: 12px; padding: 16px;">
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; letter-spacing: 2px; color: #555555; text-transform: uppercase;">Sua avaliação vale um cupom</div>
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 10px auto 0; border-collapse: collapse;">
                        @foreach ($tabela as $i => $percentual)
                            <tr>
                                <td style="padding: 2px 10px; font-size: 15px; color: #f5b301; text-align: right; white-space: nowrap;">{{ str_repeat('★', $i + 1) }}</td>
                                <td style="padding: 2px 10px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 700; color: #0a8a23; white-space: nowrap;">{{ rtrim(rtrim(number_format((float) $percentual, 2, ',', '.'), '0'), ',') }}% de desconto</td>
                            </tr>
                        @endforeach
                    </table>
                    <div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #555555; margin-top: 8px;">O código aparece na hora, assim que você enviar a avaliação.</div>
                </td>
            </tr>
        </table>
    @endif

    <p style="margin: 0; color: #8a8a8a; font-size: 12px;">
        Você recebeu este e-mail porque comprou na KazaKora.
        @if ($linkSair)
            <a href="{{ $linkSair }}" style="color: #8a8a8a;">Não quero mais receber promoções</a>.
        @endif
    </p>
@endsection
