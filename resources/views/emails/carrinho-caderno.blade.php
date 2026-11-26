{{-- D+5 da sequência de carrinho abandonado (pedido do Lira 26/11/2026): Caderno KazaKora com a matéria do blog ligada ao produto + o produto. Sem preço nem desconto. --}}
@extends('emails.layout')

@section('title', $materia['titulo'] ?? $caderno)
@section('preheader', \Illuminate\Support\Str::limit($materia['resumo'] ?? "O {$produto} continua no seu carrinho.", 100))

@section('content')
    @include('emails.partials.titulo', ['texto' => "{$nome}, separamos uma leitura para você"])

    <p style="margin: 0 0 22px; text-align: center; color: #6b7280; font-size: 14px;">
        Saiu no {{ $caderno }}, o blog da loja, e tem tudo a ver com o produto que ficou no seu carrinho.
    </p>

    @if ($materia)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
            @if ($materia['imagem'])
                <tr>
                    <td>
                        <a href="{{ $materia['link'] }}" target="_blank" style="text-decoration: none;">
                            <img src="{{ $materia['imagem'] }}" alt="{{ $materia['titulo'] }}" width="536" style="display: block; width: 100%; max-width: 536px; height: auto;">
                        </a>
                    </td>
                </tr>
            @endif
            <tr>
                <td style="padding: 18px 20px 20px; font-family: Arial, Helvetica, sans-serif;">
                    <div style="font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: {{ $marcaEmail['cor'] ?? '#f27a2a' }};">{{ $materia['tipo'] }}</div>
                    <a href="{{ $materia['link'] }}" target="_blank" style="display: block; margin: 6px 0 8px; font-size: 19px; line-height: 1.3; font-weight: 800; color: #111827; text-decoration: none;">{{ $materia['titulo'] }}</a>
                    <p style="margin: 0 0 14px; font-size: 14px; line-height: 1.6; color: #4b5563;">{{ $materia['resumo'] }}</p>
                    <a href="{{ $materia['link'] }}" target="_blank" style="font-size: 14px; font-weight: 700; color: {{ $marcaEmail['cor'] ?? '#f27a2a' }}; text-decoration: none;">Ler a matéria &rarr;</a>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 8px; background: #f9fafb; border-radius: 12px;">
        <tr>
            <td style="padding: 18px 20px; text-align: center; font-family: Arial, Helvetica, sans-serif;">
                @if ($imagemProduto)
                    <img src="{{ $imagemProduto }}" alt="{{ $produto }}" width="160" style="display: inline-block; width: 160px; max-width: 60%; height: auto; border-radius: 10px; margin: 0 0 10px;">
                @endif
                <p style="margin: 0; font-size: 15px; line-height: 1.5; color: #374151;">
                    O <strong>{{ $produto }}</strong> continua guardado no seu carrinho.
                </p>
            </td>
        </tr>
    </table>

    @include('emails.partials.botao', ['url' => $linkProduto, 'texto' => 'Ver o produto', 'cor' => $marcaEmail['cor'] ?? '#f27a2a'])

    <p style="margin: 0; text-align: center; color: #9ca3af; font-size: 12px; line-height: 1.6;">
        Você recebe este e-mail porque deixou um produto no carrinho da loja.<br>
        Não quer mais receber? <a href="{{ $linkSair }}" style="color: #9ca3af;">Sair com 1 clique</a>.
    </p>
@endsection
