<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth" style="--vh:8px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.scss', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans text-sm bg-primary">
        {{ $slot }}

        @livewireScripts

        @stack('scripts')
    </body>
</html>
