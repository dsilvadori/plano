<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(250,204,21,0.12),_transparent_24%),linear-gradient(180deg,_#050816_0%,_#09101f_100%)] font-sans text-slate-50 antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-8">
        <a href="/" class="mb-6 flex items-center gap-4 text-slate-200">
            <x-application-logo class="h-14 w-auto object-contain" />
            <div>
                <p class="text-sm uppercase tracking-[0.3em] text-slate-400">Vencendo Concursos</p>
                <p class="text-lg font-semibold">Plano de Estudos</p>
            </div>
        </a>

        <div class="w-full max-w-md rounded-3xl border border-white/10 bg-white/10 p-8 shadow-2xl shadow-black/30 backdrop-blur">
            {{ $slot }}
        </div>
    </div>
</body>
</html>
