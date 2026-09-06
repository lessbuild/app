<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <meta name="description" content="Current availability of BuildPusher services.">
    <title>{{ __('BuildPusher Status') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-secondary text-primary">
    <main class="mx-auto max-w-4xl px-4 py-10 sm:px-6 sm:py-16">
        <header class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a href="/" class="text-sm font-black uppercase tracking-[.18em] text-ternary">{{ config('app.name') }}</a>
                <h1 class="mt-3 text-3xl font-black tracking-tight text-primary sm:text-4xl">{{ __('Service status') }}</h1>
                <p class="mt-2 text-secondary">{{ __('Live availability for BuildPusher’s public services.') }}</p>
            </div>
            <a href="{{ route('platform-status.report') }}" class="text-sm font-semibold text-ternary underline">{{ __('View JSON') }}</a>
        </header>

        <section @class([
            'mt-10 rounded-2xl border p-6 shadow-xs',
            'border-green-300 bg-green-50 text-green-900' => $snapshot['operational'],
            'border-amber-300 bg-amber-50 text-amber-950' => ! $snapshot['operational'],
        ]) aria-live="polite">
            <div class="flex items-center gap-3">
                <span @class(['h-3 w-3 rounded-full', 'bg-green-500' => $snapshot['operational'], 'bg-amber-500' => ! $snapshot['operational']])></span>
                <h2 class="text-xl font-black">{{ $snapshot['operational'] ? __('All systems operational') : __('Some systems are degraded') }}</h2>
            </div>
        </section>

        <section class="mt-6 overflow-hidden rounded-2xl border border-primary bg-primary shadow-xs" aria-label="{{ __('Services') }}">
            @foreach ($snapshot['components'] as $component)
                <article class="flex flex-col gap-3 border-b border-primary p-5 last:border-0 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <h2 class="font-bold text-primary">{{ $component['name'] }}</h2>
                        <p class="mt-1 text-sm leading-6 text-secondary">{{ $component['description'] }}</p>
                    </div>
                    <span @class([
                        'w-fit shrink-0 rounded-full px-3 py-1 text-sm font-bold',
                        'bg-green-100 text-green-700' => $component['operational'],
                        'bg-amber-100 text-amber-800' => ! $component['operational'],
                    ])>{{ __($component['status']) }}</span>
                </article>
            @endforeach
        </section>

        <footer class="mt-8 flex flex-col gap-2 text-sm text-secondary sm:flex-row sm:items-center sm:justify-between">
            <p>{{ __('Automatically checked at :time', ['time' => \Illuminate\Support\Carbon::parse($snapshot['checked_at'])->format('Y-m-d H:i:s').' UTC']) }}</p>
            <p>{{ __('Detailed infrastructure diagnostics are restricted to workspace administrators.') }}</p>
        </footer>
    </main>
</body>
</html>
