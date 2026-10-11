@extends('emails.layout')

@section('title', 'Mensagem do site')
@section('preheader', $mensagem->nome.' escreveu: '.\Illuminate\Support\Str::limit($mensagem->mensagem, 90))

@section('content')
    @include('emails.partials.titulo', ['icone' => '✉️', 'texto' => 'Nova mensagem do site'])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 18px; background-color: #f8fafc; border-radius: 10px; font-size: 14px; color: #374151;">
        <tr><td style="padding: 14px 18px 4px;"><strong style="color: #111827;">Nome:</strong> {{ $mensagem->nome }}</td></tr>
        <tr><td style="padding: 4px 18px;"><strong style="color: #111827;">E-mail:</strong> <a href="mailto:{{ $mensagem->email }}" style="color: #f27a2a;">{{ $mensagem->email }}</a></td></tr>
        @if ($mensagem->telefone)
            <tr><td style="padding: 4px 18px;"><strong style="color: #111827;">Telefone:</strong> {{ $mensagem->telefone }}</td></tr>
        @endif
        <tr><td style="padding: 4px 18px 14px;"><strong style="color: #111827;">Assunto:</strong> {{ $mensagem->assunto }}</td></tr>
    </table>

    <div style="padding: 16px 18px; border-left: 4px solid #f27a2a; background-color: #fff7ed; border-radius: 0 10px 10px 0; font-size: 15px; color: #111827;">
        {!! nl2br(e($mensagem->mensagem)) !!}
    </div>

    @include('emails.partials.botao', ['url' => 'mailto:'.$mensagem->email.'?subject='.rawurlencode('Re: '.$mensagem->assunto), 'texto' => 'Responder '.\Illuminate\Support\Str::before($mensagem->nome, ' ')])

    <p style="margin: 0; text-align: center; font-size: 12px; color: #9ca3af;">
        Recebida em {{ $mensagem->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i') }}
    </p>
@endsection
