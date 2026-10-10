@extends('emails.layout')

@section('title', 'Redefinir senha')
@section('preheader', 'Recebemos um pedido para redefinir a senha da sua conta.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '🔑', 'texto' => 'Redefinir sua senha'])

    <p style="margin: 0 0 8px; text-align: center; color: #374151;">
        Olá{{ $user->name ? ', '.$user->name : '' }}! Recebemos um pedido para redefinir a senha da sua conta na
        KazaKora. Clique no botão abaixo para escolher uma nova senha.
    </p>

    @include('emails.partials.botao', ['url' => $resetUrl, 'texto' => 'Criar nova senha'])

    <p style="margin: 0 0 8px; text-align: center; color: #6b7280; font-size: 13px;">
        Este link expira em {{ $expireMinutes }} minutos. Se você não pediu a redefinição, pode ignorar
        este e-mail — sua senha atual continua a mesma.
    </p>

    <p style="margin: 16px 0 0; word-break: break-all; font-size: 12px; color: #9ca3af;">
        Se o botão não funcionar, copie e cole este link no navegador:<br>
        <a href="{{ $resetUrl }}" style="color: #f27a2a;">{{ $resetUrl }}</a>
    </p>
@endsection
