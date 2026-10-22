@extends('emails.layout')

@section('title', 'Bem-vindo à KazaKora')
@section('preheader', 'Sua conta na KazaKora está pronta. Frete grátis em todos os produtos!')

@section('content')
    @include('emails.partials.titulo', ['icone' => '👋', 'texto' => "Bem-vindo(a), {$user->name}!"])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        Sua conta na KazaKora foi criada com sucesso. A partir de agora você acompanha seus pedidos,
        guarda seus favoritos e finaliza as compras bem mais rápido.
    </p>

    @if ($senhaTemporaria)
        <p style="margin: 0 0 8px; color: #374151;">
            Criamos sua conta junto com o seu pedido. Para entrar, use:
        </p>
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 0 12px; background-color: #fff7ed; border: 1px dashed #f27a2a; border-radius: 10px; width: 100%;">
            <tr>
                <td style="padding: 16px 18px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #111827;">
                    <strong>E-mail:</strong> {{ $user->email }}<br>
                    <strong>Senha temporária:</strong> <span style="font-family: 'Courier New', monospace; font-size: 18px; font-weight: 700; letter-spacing: 2px;">{{ $senhaTemporaria }}</span>
                </td>
            </tr>
        </table>
        <p style="margin: 0 0 8px; color: #6b7280; font-size: 13px;">
            &#128274; Por segurança, no primeiro acesso vamos pedir para você criar uma senha pessoal.
        </p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 16px 0 0;">
        <tr>
            <td width="33%" style="padding: 8px; text-align: center; font-size: 12px; color: #374151;"><div style="font-size: 24px;">&#128666;</div><strong>Frete grátis</strong><br>em todos os produtos</td>
            <td width="34%" style="padding: 8px; text-align: center; font-size: 12px; color: #374151;"><div style="font-size: 24px;">&#9889;</div><strong>Full</strong><br>receba no mesmo dia</td>
            <td width="33%" style="padding: 8px; text-align: center; font-size: 12px; color: #374151;"><div style="font-size: 24px;">&#128184;</div><strong>Desconto no Pix</strong><br>pague e economize</td>
        </tr>
    </table>

    @include('emails.partials.botao', [
        'url' => $senhaTemporaria ? route('entrar') : route('catalogo.inicio'),
        'texto' => $senhaTemporaria ? 'Acessar minha conta' : 'Ver produtos',
    ])

    <p style="margin: 0; text-align: center; color: #9ca3af; font-size: 12px;">
        Se você não criou essa conta, pode ignorar este e-mail com segurança.
    </p>
@endsection
