<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>{{ $page->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-secondary text-primary">
<main class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
    <header class="text-center">
        <p class="text-xs font-bold uppercase tracking-[.24em] text-ternary">{{ config('app.name') }}</p>
        <h1 class="mt-3 text-4xl font-black">{{ $page->name }}</h1>
        @if($page->description)<p class="mx-auto mt-3 max-w-xl text-secondary">{{ $page->description }}</p>@endif
    </header>
    @php($operational=collect($components)->every(fn($component)=>$component['operational']))
    <section class="mt-10 rounded-2xl border p-6 {{ $operational ? 'border-green-300 bg-green-50 text-green-900' : 'border-amber-300 bg-amber-50 text-amber-950' }}">
        <div class="flex items-center gap-3"><span class="h-3 w-3 rounded-full {{ $operational ? 'bg-green-500' : 'bg-amber-500' }}"></span><h2 class="text-xl font-black">{{ $operational ? __('All systems operational') : __('Some systems are degraded') }}</h2></div>
    </section>
    <section class="mt-6 overflow-hidden rounded-2xl border border-primary bg-primary">
        @foreach($components as $component)<article class="flex flex-wrap items-center gap-4 border-b border-primary p-5 last:border-0"><div class="min-w-0 flex-1"><h3 class="font-bold">{{ $component['name'] }}</h3><p class="mt-1 text-xs text-secondary">{{ $component['uptime_30d'] !== null ? __(':uptime% uptime over 30 days',['uptime'=>$component['uptime_30d']]) : __('Uptime history is being collected') }}</p></div><span class="rounded-full px-3 py-1 text-sm font-bold {{ $component['operational'] ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-800' }}">{{ __($component['status']) }}</span></article>@endforeach
    </section>

    @if($incidents->isNotEmpty())
        <section class="mt-8"><h2 class="text-xl font-black">{{ __('Incident and maintenance history') }}</h2><div class="mt-4 space-y-3">@foreach($incidents as $incident)<article class="rounded-xl border border-primary bg-primary p-5"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ str($incident->kind)->headline() }} · {{ str($incident->severity)->headline() }}</p><h3 class="mt-1 font-black">{{ $incident->title }}</h3></div><span class="rounded-full bg-secondary px-3 py-1 text-xs font-bold text-secondary">{{ str($incident->status)->headline() }}</span></div><p class="mt-3 whitespace-pre-wrap text-sm text-secondary">{{ $incident->message }}</p><p class="mt-3 text-xs text-secondary">{{ $incident->starts_at->utc()->format('M j, Y H:i').' UTC' }}@if($incident->ends_at) – {{ $incident->ends_at->utc()->format('M j, Y H:i').' UTC' }}@endif</p></article>@endforeach</div></section>
    @endif

    <section class="mt-8 rounded-2xl border border-primary bg-primary p-5"><h2 class="font-black">{{ __('Get status updates') }}</h2><p class="mt-1 text-sm text-secondary">{{ __('Receive incident and planned-maintenance updates by email. Confirmation is required.') }}</p>@if(session('status_subscription'))<p class="mt-3 rounded-lg bg-green-50 p-3 text-sm text-green-800">{{ session('status_subscription') }}</p>@endif<form method="POST" action="{{ route('status.subscriptions.store', $page->slug) }}" class="mt-4 flex flex-col gap-3 sm:flex-row">@csrf<label class="sr-only" for="status-email">{{ __('Email address') }}</label><input id="status-email" type="email" name="email" required autocomplete="email" class="input secondary min-w-0 flex-1 rounded-sm" placeholder="you@example.com"><button class="button primary" type="submit">{{ __('Subscribe') }}</button></form>@error('email')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</section>

    <footer class="mt-8 text-center text-xs text-secondary">{{ __('Updated :time',['time'=>now()->utc()->format('Y-m-d H:i').' UTC']) }} · <a href="{{ route('status.report',$page->slug) }}" class="underline">JSON</a></footer>
</main>
</body>
</html>
