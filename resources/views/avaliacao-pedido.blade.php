{{-- Link de avaliação do pedido (pedido do Lira 2026-11-23): página pública assinada, sem login, com a marca da loja do pedido. --}}
@php
    $cor = $marca['cor'] ?? '#f27a2a';
    $enviada = session('avaliacao_enviada');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="{{ $cor }}">
    <title>Avalie sua compra | {{ $marca['nome'] }}</title>
    <style>
        :root { --cor: {{ $cor }}; --tinta: #14202e; --suave: #6b7280; --linha: #e5e7eb; --fundo: #f2f5f4; --estrela: #f5a623; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--fundo); color: var(--tinta); font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 16px; line-height: 1.5; }
        .topo { background: #fff; border-bottom: 4px solid var(--cor); padding: 16px; text-align: center; }
        .topo img { height: 40px; width: auto; max-width: 70%; }
        main { max-width: 560px; margin: 0 auto; padding: 20px 16px 40px; }
        h1 { font-size: 22px; line-height: 1.3; margin: 0 0 6px; }
        .sub { color: var(--suave); margin: 0 0 20px; font-size: 15px; }
        .aviso { border-radius: 12px; padding: 14px 16px; margin: 0 0 16px; font-size: 15px; }
        .aviso.ok { background: #e8f8ec; color: #136c2e; }
        .aviso.erro { background: #fdecec; color: #9b1c1c; }
        .produto { background: #fff; border-radius: 14px; padding: 16px; margin: 0 0 14px; box-shadow: 0 1px 4px rgba(16, 24, 40, .06); }
        .cabeca { display: flex; gap: 12px; align-items: center; margin: 0 0 10px; }
        .cabeca img, .cabeca .sem-foto { width: 60px; height: 60px; border-radius: 10px; object-fit: cover; flex: none; background: #f3f4f6; }
        .cabeca strong { font-size: 15px; line-height: 1.35; }
        .estrelas { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; margin: 4px 0 2px; border: 0; padding: 0; }
        .estrelas legend { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
        .estrelas input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .estrelas label { font-size: 40px; line-height: 1; color: #d1d5db; cursor: pointer; padding: 2px; -webkit-tap-highlight-color: transparent; }
        .estrelas input:checked ~ label, .estrelas label:hover, .estrelas label:hover ~ label { color: var(--estrela); }
        .estrelas input:focus-visible + label { outline: 2px solid var(--cor); outline-offset: 2px; border-radius: 6px; }
        .dica { color: var(--suave); font-size: 13px; margin: 0 0 10px; }
        textarea { width: 100%; min-height: 84px; border: 1px solid var(--linha); border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 16px; resize: vertical; }
        textarea:focus { outline: 2px solid var(--cor); border-color: transparent; }
        .fotos { margin-top: 10px; font-size: 14px; color: var(--suave); }
        .fotos input { display: block; margin-top: 6px; font-size: 14px; max-width: 100%; }
        button { width: 100%; border: 0; border-radius: 999px; background: var(--cor); color: #fff; font: inherit; font-weight: 700; font-size: 17px; padding: 15px 20px; cursor: pointer; margin-top: 6px; }
        button:active { transform: translateY(1px); }
        .rodape { text-align: center; color: var(--suave); font-size: 13px; margin-top: 18px; }
        .rodape a { color: var(--cor); }
    </style>
</head>
<body>
    <header class="topo">
        <a href="{{ $marca['site'] }}"><img src="{{ $marca['logo'] }}" alt="{{ $marca['nome'] }}"></a>
    </header>

    <main>
        @if ($enviada)
            <div class="aviso ok" role="status"><strong>Obrigado, {{ $nome }}!</strong> Sua avaliação já está no site e ajuda muita gente a escolher. Se quiser, ainda dá para mudar abaixo.</div>
        @endif

        @if ($errors->any())
            <div class="aviso erro" role="alert">{{ $errors->first() }}</div>
        @endif

        <h1>{{ $nome }}, o que você achou da sua compra?</h1>
        <p class="sub">Pedido {{ $numero }}. Toque nas estrelas: vale qualquer nota, de 1 a 5. O comentário e as fotos são opcionais.</p>

        @if (! $aberto)
            <div class="aviso erro">Este pedido não pode mais ser avaliado por aqui. Qualquer dúvida, fale com a gente pelo <a href="{{ $marca['whatsapp_link'] }}">WhatsApp</a>.</div>
        @else
            <form method="post" action="{{ $acao }}" enctype="multipart/form-data">
                @csrf
                @foreach ($produtos as $produto)
                    @php $nota = (int) old("avaliacoes.{$produto['id']}.nota", $produto['nota']); @endphp
                    <section class="produto">
                        <div class="cabeca">
                            @if ($produto['imagem'])
                                <img src="{{ $produto['imagem'] }}" alt="" loading="lazy">
                            @else
                                <span class="sem-foto"></span>
                            @endif
                            <strong>{{ $produto['nome'] }}</strong>
                        </div>

                        <fieldset class="estrelas">
                            <legend>Nota para {{ $produto['nome'] }}</legend>
                            @for ($i = 5; $i >= 1; $i--)
                                <input type="radio" id="nota-{{ $produto['id'] }}-{{ $i }}" name="avaliacoes[{{ $produto['id'] }}][nota]" value="{{ $i }}" @checked($nota === $i)>
                                <label for="nota-{{ $produto['id'] }}-{{ $i }}" title="{{ $i }} {{ $i === 1 ? 'estrela' : 'estrelas' }}" aria-label="{{ $i }} {{ $i === 1 ? 'estrela' : 'estrelas' }}">★</label>
                            @endfor
                        </fieldset>
                        <p class="dica">1 = não gostei · 5 = adorei</p>

                        <textarea name="avaliacoes[{{ $produto['id'] }}][comentario]" maxlength="{{ $maxComentario }}" placeholder="Conte como foi usar o produto (opcional)">{{ old("avaliacoes.{$produto['id']}.comentario", $produto['comentario']) }}</textarea>

                        @if ($produto['fotos'] < $maxFotos)
                            <label class="fotos">
                                Fotos do produto (opcional, até {{ $maxFotos - $produto['fotos'] }})
                                <input type="file" name="fotos[{{ $produto['id'] }}][]" accept="image/jpeg,image/png,image/webp" multiple>
                            </label>
                        @endif
                    </section>
                @endforeach

                <button type="submit">{{ $enviada ? 'Atualizar avaliação' : 'Enviar avaliação' }}</button>
            </form>
        @endif

        <p class="rodape">Teve algum problema com o pedido? <a href="{{ $marca['whatsapp_link'] }}">Fale com a gente</a>.</p>
    </main>
</body>
</html>
