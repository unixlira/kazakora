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
        @inertia
    </body>
</html>
