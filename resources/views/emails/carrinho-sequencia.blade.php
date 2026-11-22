{{-- Sequência de carrinho abandonado (pedido do Lira 21/11/2026): sem desconto, cupom ou preço, exceto o texto de PromocaoDoCarrinho (22/11/2026). --}}
@extends('emails.layout')

@section('title', $lembrete ? 'Seu carrinho está te esperando' : $produto)
@section('preheader', $lembrete ? "O {$produto} continua no seu carrinho." : \Illuminate\Support\Str::limit((string) $noticia, 90))

@section('content')
    @if ($lembrete)
        @include('emails.partials.titulo', ['icone' => '🛒', 'texto' => "{$nome}, você esqueceu algo no carrinho"])

        <p style="margin: 0 0 20px; text-align: center; color: #374151;">
            O <strong>{{ $produto }}</strong> continua no seu carrinho. Quando quiser, é só voltar e finalizar com calma.
        </p>
    @else
        @include('emails.partials.titulo', ['texto' => $produto])

        <p style="margin: 0 0 20px; text-align: center; color: #374151;">
            Oi, {{ $nome }}! {{ $noticia }}
        </p>
    @endif

    @if ($imagem)
        <div style="text-align: center; margin: 0 0 8px;">
            <img src="{{ $imagem }}" alt="{{ $produto }}" width="260" style="display: inline-block; width: 260px; max-width: 80%; height: auto; border-radius: 10px;">
        </div>
    @endif

    @include('emails.partials.botao', ['url' => $link, 'texto' => $lembrete || ($promocao ?? false) ? 'Voltar ao meu carrinho' : 'Ver o produto', 'cor' => $marcaEmail['cor'] ?? '#0FB930'])

    <p style="margin: 0; text-align: center; color: #9ca3af; font-size: 12px;">
        Já comprou ou não quer mais estas mensagens? <a href="{{ $linkParar }}" style="color: #9ca3af;">Não quero mais receber</a>.
    </p>
@endsection
