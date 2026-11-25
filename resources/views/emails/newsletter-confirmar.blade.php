{{-- Duplo opt-in da newsletter (pedido do Lira 24/11/2026). --}}
@extends('emails.layout')

@section('title', 'Confirme sua inscrição')
@section('preheader', 'Falta 1 clique para receber as novidades, ofertas e matérias do blog da KazaKora.')

@section('content')
    @include('emails.partials.titulo', ['texto' => $nome ? "{$nome}, falta só confirmar" : 'Falta só confirmar'])

    <p style="margin: 0 0 8px; text-align: center; color: #374151;">
        Recebemos seu pedido para entrar na newsletter da KazaKora. Toque no botão para confirmar e passar a receber novidades, ofertas e as matérias do nosso blog.
    </p>

    @include('emails.partials.botao', ['url' => $linkConfirmar, 'texto' => 'Confirmar inscrição', 'cor' => $marcaEmail['cor'] ?? '#f27a2a'])

    <p style="margin: 0; text-align: center; color: #9ca3af; font-size: 12px;">
        Não foi você? É só ignorar este e-mail: sem a confirmação você não recebe nada.
        Se mudar de ideia depois, <a href="{{ $linkSair }}" style="color: #9ca3af;">saia com 1 clique</a>.
    </p>
@endsection
