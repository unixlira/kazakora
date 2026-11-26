@extends('emails.layout')

@section('title', 'Código de acesso')
@section('preheader', 'Seu código para entrar no painel da KazaKora.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '🔐', 'texto' => 'Código de acesso ao painel'])

    <p style="margin: 0 0 8px; text-align: center; color: #374151;">
        Olá{{ $user->name ? ', '.$user->name : '' }}! Use o código abaixo para terminar de entrar no painel.
    </p>

    <p style="margin: 24px 0; text-align: center; font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #111827;">
        {{ $codigo }}
    </p>

    <p style="margin: 0 0 8px; text-align: center; color: #6b7280; font-size: 13px;">
        O código vale {{ $validadeMinutos }} minutos e só pode ser usado uma vez. Se não foi você que tentou
        entrar, troque sua senha agora: alguém sabe a senha atual.
    </p>
@endsection
