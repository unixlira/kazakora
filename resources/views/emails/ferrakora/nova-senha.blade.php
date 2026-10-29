{{-- Nova senha da conta FerraKora no layout dos e-mails da loja, com a marca da FerraKora (2026-10-28). --}}
@extends('emails.layout')

@section('title', 'Criar nova senha - FerraKora')
@section('preheader', 'Link para criar uma nova senha da sua conta no FerraKora.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '🔑', 'texto' => 'Criar nova senha'])

    <p style="margin: 0 0 20px; text-align: center; color: #374151;">
        Olá{{ $cliente->name ? ', '.$cliente->name : '' }}! Recebemos um pedido para criar uma nova senha da sua
        conta no FerraKora. Clique no botão abaixo para escolher a senha.
    </p>

    @include('emails.partials.botao', ['url' => $link, 'texto' => 'Criar nova senha'])

    <p style="margin: 0 0 8px; text-align: center; color: #6b7280; font-size: 13px; line-height: 1.5;">
        Este link vale por {{ $minutos }} minutos. Se você não pediu, pode ignorar este e-mail: sua senha continua a mesma.
    </p>
    <p style="margin: 16px 0 0; word-break: break-all; font-size: 12px; color: #9ca3af;">
        Se o botão não funcionar, copie e cole este link no navegador:<br>
        <a href="{{ $link }}" style="color: #f27a2a;">{{ $link }}</a>
    </p>
@endsection
