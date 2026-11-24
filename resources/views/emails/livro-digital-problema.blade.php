@extends('emails.layout-interno')

@section('title', 'Livro digital com problema')

@section('content')
    <h1 style="margin: 0 0 16px; font-family: Arial, sans-serif; font-size: 20px; color: #b91c1c;">
        ⚠️ {{ count($problemas) === 1 ? 'Livro digital com problema no PDF' : count($problemas).' livros digitais com problema no PDF' }}
    </h1>

    <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">{{ $origem }}</p>

    <ul style="margin: 0 0 12px; padding-left: 18px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">
        @foreach ($problemas as $p)
            <li style="margin: 0 0 8px;">
                <strong>#{{ $p['id'] }} {{ $p['titulo'] }}</strong>: {{ $p['problema'] }}
                @if (! empty($p['produtos']))
                    <br><span style="font-size: 12px; color: #6b7280;">Prometido na página de: {{ implode(', ', array_slice($p['produtos'], 0, 10)) }}{{ count($p['produtos']) > 10 ? ' e mais '.(count($p['produtos']) - 10) : '' }}</span>
                @endif
            </li>
        @endforeach
    </ul>

    <p style="margin: 0 0 12px; font-family: Arial, sans-serif; font-size: 14px; color: #374151;">
        <strong>O que fazer:</strong> suba o PDF de novo no livro em Entregáveis › Livros Digitais
        @if ($linkAdmin)
            (<a href="{{ $linkAdmin }}">abrir</a>)
        @endif
        O link que o cliente recebeu continua valendo e passa a baixar o PDF novo. Para mandar de novo o e-mail de um pedido: <code>php artisan pedido:reenviar-confirmacao &lt;pedido&gt;</code>.
    </p>
    <p style="margin: 0; font-family: Arial, sans-serif; font-size: 12px; color: #6b7280;">Este aviso não repete a cada pedido: no máximo um por livro por dia, e o livros:conferir só avisa de novo quando aparece problema novo.</p>
@endsection
