@extends('emails.layout-interno')

@section('title', $voltou ? "Coleta voltou: {$nome}" : "Coleta parada: {$nome}")

@section('content')
    <h1 style="margin: 0 0 16px; font-family: Arial, sans-serif; font-size: 20px; color: {{ $voltou ? '#166534' : '#b91c1c' }};">
        {{ $voltou ? '✅' : '⚠️' }} {{ $nome }} {{ $voltou ? 'voltou a chegar' : 'parou de chegar' }}
    </h1>

    <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">
        @if ($voltou)
            Chegou de novo em {{ $ultima ?? 'agora' }}. Não precisa fazer nada.
        @else
            Nada chegou há mais de {{ $limiteHoras }} h.
            Última chegada: <strong>{{ $ultima ?? 'nunca' }}</strong>.
        @endif
    </p>

    @if ($detalhe)
        <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">{{ $detalhe }}</p>
    @endif

    @unless ($voltou)
        <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;"><strong>O que conferir:</strong> {{ $dica }}</p>
        <p style="margin: 0; font-family: Arial, sans-serif; font-size: 12px; color: #6b7280;">Este aviso não repete de hora em hora: o próximo e-mail desta fonte é quando ela voltar.</p>
    @endunless
@endsection
