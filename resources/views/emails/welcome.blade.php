@extends('emails.layout')

@section('title', 'Bem-vindo à KazaKora')

@section('content')
    <h1 style="margin: 0 0 16px; font-family: Georgia, 'Times New Roman', serif; font-size: 22px; font-weight: 600; color: #1b3a5c;">
        Bem-vindo(a), {{ $user->name }}!
    </h1>

    <p style="margin: 0 0 16px;">
        Sua conta na KazaKora foi criada com sucesso. A partir de agora você pode acompanhar seus pedidos,
        guardar produtos favoritos e finalizar suas compras mais rápido.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px 0;">
        <tr>
            <td style="border-radius: 8px; background-color: #f27a2a;">
                <a href="{{ route('catalogo.inicio') }}" style="display: inline-block; padding: 12px 28px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                    Ver produtos
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 0; color: #526075; font-size: 13px;">
        Se você não criou essa conta, pode ignorar este e-mail com segurança.
    </p>
@endsection
