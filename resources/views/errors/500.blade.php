<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('Unexpected error') }} · {{ config('app.name') }}</title>
        <style>
            :root { color-scheme: light dark; font-family: ui-sans-serif, system-ui, sans-serif; }
            body { align-items: center; background: #0f172a; color: #e2e8f0; display: flex; margin: 0; min-height: 100vh; padding: 1.5rem; }
            main { background: #1e293b; border: 1px solid #475569; border-radius: .75rem; box-sizing: border-box; margin: auto; max-width: 40rem; padding: 2rem; width: 100%; }
            .eyebrow { color: #93c5fd; font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
            h1 { font-size: 1.75rem; margin: .75rem 0; }
            p { color: #cbd5e1; line-height: 1.6; }
            .reference { background: #0f172a; border-radius: .5rem; color: #bfdbfe; font-family: ui-monospace, monospace; overflow-wrap: anywhere; padding: .75rem; }
            nav { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
            a { border: 1px solid #60a5fa; border-radius: .5rem; color: #dbeafe; font-weight: 600; padding: .65rem 1rem; text-decoration: none; }
            a:first-child { background: #2563eb; color: white; }
            a:focus-visible { outline: 3px solid #93c5fd; outline-offset: 3px; }
        </style>
    </head>
    <body>
        <main>
            <div class="eyebrow">{{ config('app.name') }} · {{ __('Error 500') }}</div>
            <h1>{{ __('Something went wrong') }}</h1>
            <p>{{ __('The request could not be completed. You can try again, return to the dashboard, or give the reference below to the operator if the problem continues.') }}</p>
            <p class="reference"><strong>{{ __('Reference:') }}</strong> {{ $incidentId ?? __('Unavailable') }}</p>
            <nav aria-label="{{ __('Error recovery') }}">
                <a href="">{{ __('Try again') }}</a>
                <a href="/home">{{ __('Go to dashboard') }}</a>
                <a href="/">{{ __('Go to homepage') }}</a>
            </nav>
        </main>
    </body>
</html>
