@props([
    'title' => null,
    'description' => null,
])

@php($pageTitle = $title ? $title.' · '.config('app.name') : config('app.name'))

<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="min-h-full"
    data-storage-namespace="buildpusher-signal"
    data-default-preset="modern"
    data-default-appearance="system"
    data-default-palette="graphite"
    data-default-density="comfortable"
    data-default-corners="subtle"
    data-default-font="system"
    data-default-motion="system"
    data-default-contrast="default"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#f4f7fb" data-theme-color>
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $pageTitle }}</title>
        @if ($description)
            <meta name="description" content="{{ $description }}">
        @endif
        <x-signal.theme-boot />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased transition-colors">
        {{ $slot }}
        <span class="sr-only" data-theme-status aria-live="polite" aria-atomic="true"></span>
    </body>
</html>
