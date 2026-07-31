@extends('emails.layout')

@section('title', 'Redefinir senha')

@section('content')
    <h1 style="margin: 0 0 16px; font-family: Georgia, 'Times New Roman', serif; font-size: 22px; font-weight: 600; color: #1b3a5c;">
        Redefinir sua senha
    </h1>

    <p style="margin: 0 0 16px;">
        Olá{{ $user->name ? ', '.$user->name : '' }}! Recebemos um pedido para redefinir a senha da sua conta na
        KazaKora. Clique no botão abaixo para escolher uma nova senha.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px 0;">
        <tr>
            <td style="border-radius: 8px; background-color: #f27a2a;">
                <a href="{{ $resetUrl }}" style="display: inline-block; padding: 12px 28px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                    Redefinir senha
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 0 0 8px; color: #526075; font-size: 13px;">
        Este link expira em {{ $expireMinutes }} minutos. Se você não pediu a redefinição de senha, pode ignorar
        este e-mail — sua senha atual continua a mesma.
    </p>

    <p style="margin: 16px 0 0; word-break: break-all; font-size: 12px; color: #8089a0;">
        Se o botão não funcionar, copie e cole este link no navegador:<br>
        <a href="{{ $resetUrl }}" style="color: #f27a2a;">{{ $resetUrl }}</a>
    </p>
@endsection
