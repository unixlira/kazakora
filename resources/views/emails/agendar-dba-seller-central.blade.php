@extends('emails.layout-interno')

@section('title', "Agende no Seller Central: pedido {$pedidoAmazon}")

@section('content')
    <h1 style="margin: 0 0 16px; font-family: Arial, sans-serif; font-size: 20px; color: #b91c1c;">
        ⚠️ Agende no Seller Central: pedido {{ $pedidoAmazon }}
    </h1>

    <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">
        Pedido <strong>#{{ $pedidoId }}</strong> (Amazon <strong>{{ $pedidoAmazon }}</strong>).
        Prazo de envio: <strong>{{ $prazo }}</strong>.
    </p>

    <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">
        A Amazon não deixou agendar este pedido pela API: {{ $motivo }}.
    </p>

    <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">
        <strong>O que fazer:</strong> no Seller Central, abra o pedido e agende em <strong>Ponto de Envio</strong> (drop-off), na data mais próxima. Nunca coleta.
    </p>

    <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">
        O sistema confere a cada 30 minutos: quando o pacote aparecer agendado, grava o rastreio e baixa a etiqueta sozinho para a impressora.
    </p>

    @if ($link)
        <p style="margin: 0; font-family: Arial, sans-serif; font-size: 14px;"><a href="{{ $link }}">Abrir o pedido no admin</a></p>
    @endif
@endsection
