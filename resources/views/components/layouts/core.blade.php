<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth" style="--vh:8px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;;500;600;700;800&display=swap">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.9.5/ace.js"></script>
        @vite(['resources/css/app.scss', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans text-sm bg-primary">
        {{ $slot }}

        @livewireScripts

        @stack('scripts')
    </body>
</html>
