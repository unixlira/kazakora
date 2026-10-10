<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'KazaKora') }}</title>

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
