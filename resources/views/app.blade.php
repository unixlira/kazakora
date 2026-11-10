<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Verificação de propriedade do domínio no Norton Safe Web (pedido do Lira, 2026-10-23). --}}
        <meta name="norton-safeweb-site-verification" content="77EVMTMGAB7-91DHNRR4XGA1S26545597MEHYHHH7AT1758N5UGGNBO9359JJ35T2CZ0X93SDBOGZTQ333Y-72UD-SD1TZQJOXV6ZQUSV1-XP5X3D9TQOHC4GSTIA1ZF" />

        {{-- Página de produto: título, descrição, foto e dados estruturados já no HTML inicial
             (Search Console, pedido 2026-10-12). O <Head> do Vue troca o título depois pelo mesmo texto. --}}
        @php
            $seoProduto = str_starts_with($page['component'] ?? '', 'Catalog/ProductDetail') ? ($page['props']['product'] ?? null) : null;
            $seoProduto = $seoProduto !== null ? json_decode(json_encode($seoProduto), true) : null;
            $seoProduto = is_array($seoProduto) && filled($seoProduto['name'] ?? null) ? $seoProduto : null;
            // Departamento (/?categoria=slug, só com esse filtro): página própria para o Google (tarefa 4037, 10/11).
            $seoHome = ($page['component'] ?? '') === 'Catalog/Home';
            $seoDepartamento = $seoHome && array_keys(request()->query()) === ['categoria']
                ? \App\Modules\Catalog\Support\SeoDaLoja::departamento((string) request()->query('categoria'))
                : null;
            $seoHomeLimpa = $seoHome && request()->query() === [];
        @endphp
        {{-- Título com o nome da página antes da marca (tarefa 4037, 10/11), igual ao <Head> do Vue (resources/js/app.js). --}}
        <title inertia>{{ $seoProduto ? \App\Modules\Catalog\Support\SeoDaLoja::tituloDoProduto($seoProduto) : ($seoDepartamento ? $seoDepartamento['name'].' | KazaKora' : ($seoHomeLimpa ? 'KazaKora — eletrônicos, gadgets e cozinha' : config('app.name', 'KazaKora'))) }}</title>

        {{-- SEO no HTML inicial (pedido 2026-10-12): o Google lê isto antes de rodar o JavaScript.
             Canonical sem parâmetros (busca e filtros apontam para a página limpa). --}}
        @unless (request()->is('admin*'))
            @php
                $canonical = rtrim(config('app.url'), '/').'/'.ltrim(request()->path() === '/' ? '' : request()->path(), '/');
                // Variação é a mesma página do produto pai: canônica aponta para o pai.
                if ($seoProduto && ! empty($seoProduto['parent_product_id'])) {
                    $slugPai = \App\Modules\Catalog\Models\Product::query()->whereKey($seoProduto['parent_product_id'])->value('slug');
                    $canonical = $slugPai ? rtrim(config('app.url'), '/').'/produtos/'.$slugPai : $canonical;
                }
                if ($seoDepartamento) {
                    $canonical = rtrim(config('app.url'), '/').'/?categoria='.rawurlencode($seoDepartamento['slug']);
                }
                // Descrição sem emoji e sem o título em maiúsculas do cadastro (tarefa 4037, 10/11).
                $descricao = $seoProduto
                    ? \App\Modules\Catalog\Support\SeoDaLoja::descricaoDoProduto($seoProduto)
                    : ($seoDepartamento
                    ? $seoDepartamento['name'].' na KazaKora: '.$seoDepartamento['total'].' '.($seoDepartamento['total'] === 1 ? 'produto' : 'produtos').' com frete rápido e compra segura.'
                    : \App\Modules\Catalog\Support\SeoDaLoja::DESCRICAO_PADRAO);
                // Tags do produto (só do site): meta keywords e keywords do JSON-LD.
                $tagsSeo = collect($seoProduto['tags'] ?? [])->map(fn ($t) => trim((string) $t))->filter()->values()->all();
                $imagens = collect($seoProduto['images'] ?? [])->pluck('url')->filter()->take(5)->values()->all();
                // Prévia do link no WhatsApp: foto leve (pedido 2026-10-27, ver ImagemDeCompartilhamento).
                $imagemOg = $seoProduto ? \App\Modules\Catalog\Support\ImagemDeCompartilhamento::escolher($seoProduto['images'] ?? []) : null;
            @endphp
            <link rel="canonical" href="{{ $canonical }}">
            <meta name="description" content="{{ $descricao }}">
            @if ($seoProduto && $tagsSeo)
                <meta name="keywords" content="{{ implode(', ', $tagsSeo) }}">
            @endif
            <meta property="og:site_name" content="KazaKora">
            <meta property="og:type" content="{{ $seoProduto ? 'product' : 'website' }}">
            <meta property="og:url" content="{{ $canonical }}">
            <meta property="og:locale" content="pt_BR">
            @if ($seoProduto)
                <meta property="og:title" content="{{ filled($seoProduto['seo_title'] ?? null) ? $seoProduto['seo_title'] : $seoProduto['name'] }}">
                <meta property="og:description" content="{{ $descricao }}">
                @if ($imagemOg)
                    <meta property="og:image" content="{{ $imagemOg }}">
                    <meta property="og:image:alt" content="{{ filled($seoProduto['seo_title'] ?? null) ? $seoProduto['seo_title'] : $seoProduto['name'] }}">
                    <meta name="twitter:card" content="summary_large_image">
                @else
                    {{-- Produto sem foto: a prévia leva a logo da loja em vez de sair sem imagem. --}}
                    <meta property="og:image" content="{{ url('/images/marca/og-kazakora.png') }}">
                    <meta property="og:image:type" content="image/png">
                    <meta property="og:image:width" content="1200">
                    <meta property="og:image:height" content="630">
                    <meta name="twitter:card" content="summary_large_image">
                @endif
                <script type="application/ld+json">{!! json_encode(array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $seoProduto['name'],
                    'description' => $descricao,
                    'sku' => $seoProduto['sku'] ?? null,
                    'brand' => filled($seoProduto['brand'] ?? null) ? ['@type' => 'Brand', 'name' => $seoProduto['brand']] : null,
                    'image' => $imagens ?: null,
                    'keywords' => $tagsSeo ? implode(', ', $tagsSeo) : null,
                    'offers' => [
                        '@type' => 'Offer',
                        'url' => $canonical,
                        'priceCurrency' => 'BRL',
                        'price' => number_format((float) ($seoProduto['final_price'] ?? $seoProduto['price'] ?? 0), 2, '.', ''),
                        'availability' => ((int) ($seoProduto['stock'] ?? 0)) > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                        'itemCondition' => 'https://schema.org/NewCondition',
                        // Devolução por arrependimento em 7 dias (página Trocas e devoluções, art. 49 do CDC).
                        'hasMerchantReturnPolicy' => [
                            '@type' => 'MerchantReturnPolicy',
                            'applicableCountry' => 'BR',
                            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                            'merchantReturnDays' => 7,
                            'returnMethod' => 'https://schema.org/ReturnByMail',
                        ],
                    ],
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
                {{-- Trilha Início > Departamento > Produto no resultado do Google (tarefa 4037, 10/11). --}}
                @php($seoCategoria = $seoProduto['category'] ?? null)
                <script type="application/ld+json">{!! json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => array_values(array_filter([
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => rtrim(config('app.url'), '/').'/'],
                        filled($seoCategoria['slug'] ?? null) ? ['@type' => 'ListItem', 'position' => 2, 'name' => $seoCategoria['name'], 'item' => rtrim(config('app.url'), '/').'/?categoria='.rawurlencode($seoCategoria['slug'])] : null,
                        ['@type' => 'ListItem', 'position' => filled($seoCategoria['slug'] ?? null) ? 3 : 2, 'name' => $seoProduto['name'], 'item' => $canonical],
                    ])),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
                {{-- Vídeo com miniatura (pedido 2026-10-15: Search Console, "Nenhum URL de miniatura enviado"). --}}
                @php($video = \App\Modules\Catalog\Support\VideoParaGoogle::dados($seoProduto['video_path'] ?? null, $seoProduto['video_duration_seconds'] ?? null, $seoProduto['name'], $seoProduto['description'] ?? null, $seoProduto['images'][0]['media_url'] ?? $imagens[0] ?? null, $seoProduto['created_at'] ?? null))
                @if ($video)
                    <meta property="og:video" content="{{ $video['conteudo'] }}">
                    <script type="application/ld+json">{!! json_encode(\App\Modules\Catalog\Support\VideoParaGoogle::schema($video), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
                @endif
            @else
                {{-- Link da loja compartilhado no WhatsApp/Instagram/Facebook saía sem logo
                     (pedido 2026-10-23): fora da página de produto vai a logo oficial 1200x630. --}}
                <meta property="og:title" content="{{ $seoDepartamento ? $seoDepartamento['name'].' | KazaKora' : config('app.name', 'KazaKora') }}">
                <meta property="og:description" content="{{ $descricao }}">
                <meta property="og:image" content="{{ url('/images/marca/og-kazakora.png') }}">
                <meta property="og:image:type" content="image/png">
                <meta property="og:image:width" content="1200">
                <meta property="og:image:height" content="630">
                <meta property="og:image:alt" content="Logo KazaKora">
                <meta name="twitter:card" content="summary_large_image">
                @if ($seoHomeLimpa)
                    {{-- Loja e busca do site para o Google (tarefa 4037, 10/11). --}}
                    <script type="application/ld+json">{!! json_encode([
                        '@context' => 'https://schema.org',
                        '@graph' => [
                            ['@type' => 'Organization', '@id' => rtrim(config('app.url'), '/').'/#loja', 'name' => 'KazaKora', 'url' => rtrim(config('app.url'), '/').'/', 'logo' => url('/images/marca/favicon-180.png')],
                            ['@type' => 'WebSite', 'name' => 'KazaKora', 'url' => rtrim(config('app.url'), '/').'/', 'inLanguage' => 'pt-BR', 'publisher' => ['@id' => rtrim(config('app.url'), '/').'/#loja'],
                                'potentialAction' => ['@type' => 'SearchAction', 'target' => rtrim(config('app.url'), '/').'/?search={search_term_string}', 'query-input' => 'required name=search_term_string']],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
                @endif
            @endif
        @endunless

        {{-- Favicon (Admin > Banners > Logos e favicon; padrão: laranja com logo branca). --}}
        @php($marca = rescue(fn () => \App\Support\Marca::urls(), ['favicon' => '/images/marca/favicon-32.png', 'faviconTipo' => 'image/png'], false))
        <link rel="icon" type="{{ $marca['faviconTipo'] }}" href="{{ $marca['favicon'] }}">
        <link rel="apple-touch-icon" href="{{ str_starts_with($marca['favicon'], '/images/marca/') ? '/images/marca/favicon-180.png' : $marca['favicon'] }}">

        {{-- Fontes do Google servidas pelo próprio site, dentro do app.css (resources/css/fontes-google.css).
             A da loja (IBM Plex Sans) é pedida já aqui, sem esperar o CSS chegar. --}}
        @unless (request()->is('admin*'))
            <link rel="preload" as="font" type="font/woff2" crossorigin href="{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/google/ibm-plex-sans-variavel-latin.woff2') }}">
            {{-- Desempenho (pedido 2026-10-21, PageSpeed): na loja, o CSS não trava
                 a primeira pintura. A tela de abertura (estilo próprio, abaixo) cobre a página
                 até a loja montar, e o app.js só a tira depois que o CSS chegou: o visual
                 não muda, só aparece mais cedo. --}}
            @php(\Illuminate\Support\Facades\Vite::useStyleTagAttributes(['media' => 'print', 'onload' => "this.media='all'", 'data-adiado' => true]))
            {{-- 1º banner da home pedido já no HTML, sem esperar o JavaScript montar a vitrine. --}}
            @php($bannerTopo = ($page['component'] ?? '') === 'Catalog/Home' && ! empty($page['props']['vitrine']) ? (json_decode(json_encode($page['props']['banners'] ?? []), true)[0] ?? null) : null)
            @if ($bannerTopo)
                <link rel="preload" as="image" fetchpriority="high" media="(max-width: 767px)"
                    href="{{ $bannerTopo['image_url_mobile'] ?? $bannerTopo['image_url'] }}"
                    @if (! empty($bannerTopo['image_srcset_mobile'])) imagesrcset="{{ $bannerTopo['image_srcset_mobile'] }}" imagesizes="100vw" @endif>
                <link rel="preload" as="image" fetchpriority="high" media="(min-width: 768px)"
                    href="{{ $bannerTopo['image_url'] }}"
                    @if (! empty($bannerTopo['image_srcset'])) imagesrcset="{{ $bannerTopo['image_srcset'] }}" imagesizes="100vw" @endif>
            @endif
            {{-- Foto grande do produto (capa do vídeo ou 1ª foto) pedida já no HTML (PageSpeed 2026-10-21,
                 LCP). srcset e sizes iguais aos da galeria (FOTO_SIZES em ProductDetailV2.vue), senão baixa duas vezes. --}}
            @php($fotoTopo = ($page['component'] ?? '') === 'Catalog/ProductDetailV2' && $seoProduto
                ? (empty($seoProduto['video_url']) ? (json_decode(json_encode($page['props']['galeria'] ?? []), true)[0] ?? null) : null) ?? ($seoProduto['images'][0] ?? null)
                : null)
            @if ($fotoTopo && ($fotoTopo['media_url'] ?? $fotoTopo['url'] ?? null))
                <link rel="preload" as="image" fetchpriority="high"
                    href="{{ $fotoTopo['media_url'] ?? $fotoTopo['url'] }}"
                    @if (! empty($fotoTopo['thumb_url']) && ! empty($fotoTopo['media_url']) && $fotoTopo['thumb_url'] !== $fotoTopo['media_url'])
                        imagesrcset="{{ $fotoTopo['thumb_url'] }} 512w, {{ $fotoTopo['media_url'] }} 1200w"
                        imagesizes="(max-width: 767px) calc(100vw - 40px), (max-width: 900px) 75vw, (max-width: 1320px) 48vw, 600px"
                    @endif>
            @endif
        @endunless
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="antialiased">
        {{-- Tela de abertura (pedido 2026-10-10): logo + carregando até a loja
             montar; some sozinha (resources/js/app.js). Fora do admin. --}}
        @unless (request()->is('admin*'))
            <div id="tela-abertura" aria-hidden="true">
                <style>
                    #tela-abertura { position: fixed; inset: 0; z-index: 9999; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 22px; background: #fff; transition: opacity .35s ease, visibility .35s ease; }
                    #tela-abertura.saindo { opacity: 0; visibility: hidden; }
                    #tela-abertura img { width: 190px; max-width: 60vw; height: auto; animation: abertura-pulsa 1.4s ease-in-out infinite; }
                    #tela-abertura .abertura-barra { width: 150px; height: 4px; border-radius: 999px; background: #f1f1f1; overflow: hidden; position: relative; }
                    #tela-abertura .abertura-barra::after { content: ''; position: absolute; inset: 0; width: 40%; border-radius: 999px; background: #f27a2a; animation: abertura-anda 1s ease-in-out infinite; }
                    @keyframes abertura-anda { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
                    @keyframes abertura-pulsa { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .75; transform: scale(.97); } }
                    @media (prefers-reduced-motion: reduce) { #tela-abertura img, #tela-abertura .abertura-barra::after { animation: none; } }
                </style>
                <img src="{{ rescue(fn () => \App\Support\Marca::urlLoja('logo_nav'), '/images/marca/logo-nav.webp', false) }}" alt="KazaKora" width="190" height="34" fetchpriority="high">
                <div class="abertura-barra"></div>
            </div>
            <script>
                // Garantia: se o JavaScript da loja falhar, a abertura não prende a tela.
                setTimeout(function () { var t = document.getElementById('tela-abertura'); if (t) t.classList.add('saindo'); }, 8000);
            </script>
        @endunless
        @if (request()->is('admin*'))
            @inertia
        @else
            {{-- Mesmo HTML do @inertia, com o conteúdo para o Google dentro do <div id="app"> (tarefa 4037, 10/11):
                 h1, texto e links. O Vue limpa o div ao montar e desenha a página de sempre. --}}
            <script data-page="app" type="application/json">{!! json_encode($page) !!}</script><div id="app">@include('partials.seo-conteudo')</div>
        @endif
    </body>
</html>
