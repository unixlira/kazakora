{{-- Resumo do blog para a newsletter (pedido do Lira 24/11/2026): imagem, título, resumo e link de cada matéria. --}}
@extends('emails.layout')

@section('title', $assunto)
@section('preheader', \Illuminate\Support\Str::limit($itens[0]['resumo'] ?? 'As novidades do blog da KazaKora.', 100))

@section('content')
    @include('emails.partials.titulo', ['texto' => $nome ? "{$nome}, saiu no blog" : 'Saiu no blog da KazaKora'])

    <p style="margin: 0 0 24px; text-align: center; color: #6b7280; font-size: 14px;">
        Dicas, receitas e notícias escolhidas para a sua casa.
    </p>

    @foreach ($itens as $item)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 22px; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
            @if ($item['imagem'])
                <tr>
                    <td>
                        <a href="{{ $item['link'] }}" target="_blank" style="text-decoration: none;">
                            <img src="{{ $item['imagem'] }}" alt="{{ $item['titulo'] }}" width="536" style="display: block; width: 100%; max-width: 536px; height: auto;">
                        </a>
                    </td>
                </tr>
            @endif
            <tr>
                <td style="padding: 18px 20px 20px; font-family: Arial, Helvetica, sans-serif;">
                    <div style="font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: {{ $marcaEmail['cor'] ?? '#f27a2a' }};">{{ $item['tipo'] }}</div>
                    <a href="{{ $item['link'] }}" target="_blank" style="display: block; margin: 6px 0 8px; font-size: 19px; line-height: 1.3; font-weight: 800; color: #111827; text-decoration: none;">{{ $item['titulo'] }}</a>
                    <p style="margin: 0 0 14px; font-size: 14px; line-height: 1.6; color: #4b5563;">{{ $item['resumo'] }}</p>
                    <a href="{{ $item['link'] }}" target="_blank" style="font-size: 14px; font-weight: 700; color: {{ $marcaEmail['cor'] ?? '#f27a2a' }}; text-decoration: none;">Ler a matéria &rarr;</a>
                </td>
            </tr>
        </table>
    @endforeach

    @include('emails.partials.botao', ['url' => $linkBlog, 'texto' => 'Ver tudo no blog', 'cor' => $marcaEmail['cor'] ?? '#f27a2a'])

    <p style="margin: 0; text-align: center; color: #9ca3af; font-size: 12px; line-height: 1.6;">
        Você recebe este e-mail porque se inscreveu na newsletter da KazaKora (kazakora.com).<br>
        Não quer mais receber? <a href="{{ $linkSair }}" style="color: #9ca3af;">Sair da lista com 1 clique</a>.
    </p>
@endsection
