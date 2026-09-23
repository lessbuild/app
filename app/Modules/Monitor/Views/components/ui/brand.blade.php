@props(['href' => null])

<a href="{{ $href ?? route('monitor.dashboard') }}" aria-label="{{ config('app.name') }} home" {{ $attributes->class(['inline-flex items-center gap-3 text-lg font-bold tracking-tight']) }}>
    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-control bg-emphasis text-emphasis-ink">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3 20 6v5c0 5-3.4 8.6-8 10-4.6-1.4-8-5-8-10V6l8-3Z" stroke="currentColor" stroke-width="1.7"/><path d="m8.5 12 2.2 2.2 4.8-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </span>
    <span>{{ config('app.name') }}</span>
</a>
