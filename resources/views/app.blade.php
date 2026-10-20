<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Página de produto: título, descrição, foto e dados estruturados já no HTML inicial
             (Search Console, pedido 2026-10-12). O <Head> do Vue troca o título depois pelo mesmo texto. --}}
        @php
            $seoProduto = str_starts_with($page['component'] ?? '', 'Catalog/ProductDetail') ? ($page['props']['product'] ?? null) : null;
            $seoProduto = $seoProduto !== null ? json_decode(json_encode($seoProduto), true) : null;
            $seoProduto = is_array($seoProduto) && filled($seoProduto['name'] ?? null) ? $seoProduto : null;
        @endphp
        <title inertia>{{ config('app.name', 'KazaKora').($seoProduto ? ' - '.$seoProduto['name'] : '') }}</title>

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
                $descricao = $seoProduto && filled($seoProduto['description'] ?? null)
                    ? \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($seoProduto['description'])))), 155)
                    : 'KazaKora: eletrônicos, gadgets e utilidades para a casa com frete rápido, compra segura e 5% de desconto no Pix.';
                $imagens = collect($seoProduto['images'] ?? [])->pluck('url')->filter()->take(5)->values()->all();
            @endphp
            <link rel="canonical" href="{{ $canonical }}">
            <meta name="description" content="{{ $descricao }}">
            <meta property="og:site_name" content="KazaKora">
            <meta property="og:type" content="{{ $seoProduto ? 'product' : 'website' }}">
            <meta property="og:url" content="{{ $canonical }}">
            <meta property="og:locale" content="pt_BR">
            @if ($seoProduto)
                <meta property="og:title" content="{{ $seoProduto['name'] }}">
                <meta property="og:description" content="{{ $descricao }}">
                @if ($imagens)
                    <meta property="og:image" content="{{ $imagens[0] }}">
                @endif
                <script type="application/ld+json">{!! json_encode(array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $seoProduto['name'],
                    'description' => $descricao,
                    'sku' => $seoProduto['sku'] ?? null,
                    'brand' => filled($seoProduto['brand'] ?? null) ? ['@type' => 'Brand', 'name' => $seoProduto['brand']] : null,
                    'image' => $imagens ?: null,
                    'offers' => [
                        '@type' => 'Offer',
                        'url' => $canonical,
                        'priceCurrency' => 'BRL',
                        'price' => number_format((float) ($seoProduto['final_price'] ?? $seoProduto['price'] ?? 0), 2, '.', ''),
                        'availability' => ((int) ($seoProduto['stock'] ?? 0)) > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                        'itemCondition' => 'https://schema.org/NewCondition',
                    ],
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
                {{-- Vídeo com miniatura (pedido 2026-10-15: Search Console, "Nenhum URL de miniatura enviado"). --}}
                @php($video = \App\Modules\Catalog\Support\VideoParaGoogle::dados($seoProduto['video_path'] ?? null, $seoProduto['video_duration_seconds'] ?? null, $seoProduto['name'], $seoProduto['description'] ?? null, $seoProduto['images'][0]['media_url'] ?? $imagens[0] ?? null, $seoProduto['created_at'] ?? null))
                @if ($video)
                    <meta property="og:video" content="{{ $video['conteudo'] }}">
                    <script type="application/ld+json">{!! json_encode(\App\Modules\Catalog\Support\VideoParaGoogle::schema($video), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
                @endif
            @endif
        @endunless

        {{-- Favicon (Admin > Banners > Logos e favicon; padrão: laranja com logo branca). --}}
        @php($marca = rescue(fn () => \App\Support\Marca::urls(), ['favicon' => '/images/marca/favicon-32.png', 'faviconTipo' => 'image/png'], false))
        <link rel="icon" type="{{ $marca['faviconTipo'] }}" href="{{ $marca['favicon'] }}">
        <link rel="apple-touch-icon" href="{{ str_starts_with($marca['favicon'], '/images/marca/') ? '/images/marca/favicon-180.png' : $marca['favicon'] }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
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
                <img src="{{ rescue(fn () => \App\Support\Marca::url('logo_nav'), '/images/marca/logo-nav.png', false) }}" alt="KazaKora" width="190" height="34">
                <div class="abertura-barra"></div>
            </div>
            <script>
                // Garantia: se o JavaScript da loja falhar, a abertura não prende a tela.
                setTimeout(function () { var t = document.getElementById('tela-abertura'); if (t) t.classList.add('saindo'); }, 8000);
            </script>
        @endunless
        @inertia
    </body>
</html>
