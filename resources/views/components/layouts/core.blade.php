@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'indexable' => false,
    'livewire' => true,
])

@php($pageTitle = $title ? $title.' · '.config('app.name') : config('app.name'))

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="motion-safe:scroll-smooth" style="--vh:8px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#111827">
        <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, nofollow' }}">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="manifest" href="/manifest.webmanifest">
        <title>{{ $pageTitle }}</title>
        @if ($description)
            <meta name="description" content="{{ $description }}">
        @endif
        @if ($indexable && $canonical)
            <link rel="canonical" href="{{ $canonical }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:title" content="{{ $pageTitle }}">
            <meta property="og:url" content="{{ $canonical }}">
            @if ($description)
                <meta property="og:description" content="{{ $description }}">
            @endif
            <meta name="twitter:card" content="summary">
            <meta name="twitter:title" content="{{ $pageTitle }}">
            @if ($description)
                <meta name="twitter:description" content="{{ $description }}">
            @endif
        @endif
        @vite('resources/css/app.css')
        @if (! $livewire)
            @vite('resources/js/alpine.js')
        @endif
        @if ($livewire)
            @livewireStyles
        @endif
    </head>
    <body class="font-sans text-sm bg-primary">
        {{ $slot }}

        @if ($livewire)
            @livewireScripts
        @endif

        @stack('scripts')
        <script src="/service-worker-register.js" defer></script>
    </body>
</html>
