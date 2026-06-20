<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#050816">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Plano VC">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" href="https://vencendoconcursos.com.br/wp-content/uploads/2020/05/cropped-logo-comercial-2-32x32.png">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

    <script>
        (() => {
            const theme = localStorage.getItem('vc-theme') || 'dark';

            document.documentElement.dataset.theme = theme;
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'light' ? '#f1f5f9' : '#050816');
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="app-shell">
    <div class="app-frame min-h-screen bg-[radial-gradient(circle_at_top,_rgba(59,130,246,0.12),_transparent_28%),linear-gradient(180deg,_#050816_0%,_#09101f_100%)] text-slate-50">
        @include('layouts.navigation')

        <div class="lg:pl-72">
            @isset($header)
                <header class="border-b border-white/10 bg-white/5 backdrop-blur">
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
