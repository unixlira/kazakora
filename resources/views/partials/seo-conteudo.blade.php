{{-- Conteúdo para o Google dentro do <div id="app"> (tarefa 4037, 10/11). O Vue apaga
     este bloco ao montar a loja; a tela de abertura cobre esse instante. Só texto e
     links que a própria página mostra: nada aqui é escondido do visitante. --}}
@php
    $seo = \App\Modules\Catalog\Support\SeoDaLoja::class;
    $departamentosSeo = rescue(fn () => \App\Modules\Catalog\Support\MenuDaLoja::departamentos(), [], false);
@endphp
<div class="seo-conteudo">
    <header>
        <a href="/">KazaKora</a>
        <nav aria-label="Departamentos">
            <ul>
                @foreach ($departamentosSeo as $dep)
                    <li><a href="/?categoria={{ rawurlencode($dep['slug']) }}">{{ $dep['name'] }}</a></li>
                @endforeach
                <li><a href="/ofertas">Ofertas</a></li>
            </ul>
        </nav>
    </header>

    <main>
        @if ($seoProduto)
            <nav aria-label="Trilha">
                <a href="/">Início</a>
                @if (filled($seoProduto['category']['slug'] ?? null))
                    › <a href="/?categoria={{ rawurlencode($seoProduto['category']['slug']) }}">{{ $seoProduto['category']['name'] }}</a>
                @endif
            </nav>
            <h1>{{ $seoProduto['name'] }}</h1>
            <p>R$ {{ number_format((float) ($seoProduto['final_price'] ?? $seoProduto['price'] ?? 0), 2, ',', '.') }}</p>
            @php($textoSeo = $seo::textoLimpo($seoProduto['description'] ?? ''))
            @if ($textoSeo !== '')
                <p>{{ \Illuminate\Support\Str::limit($textoSeo, 1500, '...') }}</p>
            @endif
            @php($relacionadosSeo = json_decode(json_encode($page['props']['relatedProducts'] ?? []), true) ?: [])
            @if ($relacionadosSeo)
                <h2>Você também pode gostar</h2>
                <ul>
                    @foreach ($relacionadosSeo as $rel)
                        @if (filled($rel['slug'] ?? null))
                            <li><a href="/produtos/{{ $rel['slug'] }}">{{ $rel['name'] }}</a></li>
                        @endif
                    @endforeach
                </ul>
            @endif
        @elseif ($seoHome)
            <h1>{{ $seoDepartamento['name'] ?? 'KazaKora: eletrônicos, gadgets e utilidades para a casa' }}</h1>
            <p>{{ $descricao }}</p>
            @php($produtosSeo = collect($seo::produtosDaVitrine())->when($seoDepartamento, fn ($c) => $c->where('category', $seoDepartamento['slug'])))
            <ul>
                @foreach ($produtosSeo as $p)
                    <li><a href="/produtos/{{ $p['slug'] }}">{{ $p['name'] }}</a></li>
                @endforeach
            </ul>
        @endif
    </main>

    <footer>
        <ul>
            <li><a href="/quem-somos">Quem somos</a></li>
            <li><a href="/fale-conosco">Fale conosco</a></li>
            <li><a href="/politica-de-entrega">Política de entrega</a></li>
            <li><a href="/trocas-e-devolucoes">Trocas e devoluções</a></li>
            <li><a href="/politica-de-privacidade">Política de privacidade</a></li>
            <li><a href="/termos-de-uso">Termos de uso</a></li>
        </ul>
    </footer>
</div>
