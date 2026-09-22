@props([
    'description',
    'exploreLinks',
    'closingEyebrow',
    'closingCopy',
    'actionHref',
    'actionLabel',
])

<footer class="border-t border-line bg-surface">
    <div class="mx-auto grid max-w-content gap-10 px-5 py-12 sm:px-8 md:grid-cols-[1.4fr_1fr_1fr] md:py-16">
        <div>
            <a href="{{ url('/') }}" class="flex items-center gap-3 text-base font-extrabold tracking-tight text-ink">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface shadow-soft">
                    <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
                </span>
                {{ config('app.name') }}
            </a>
            <p class="mt-4 max-w-xs text-sm leading-6 text-muted">{{ $description }}</p>
        </div>

        <div>
            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Explore') }}</p>
            <nav class="mt-4 flex flex-col items-start gap-3" aria-label="{{ __('Footer navigation') }}">
                @foreach ($exploreLinks as $link)
                    <a href="{{ $link['href'] }}" class="text-sm font-semibold text-muted transition hover:text-ink">{{ $link['label'] }}</a>
                @endforeach
            </nav>
        </div>

        <div>
            <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $closingEyebrow }}</p>
            <p class="mt-4 text-sm leading-6 text-muted">{{ $closingCopy }}</p>
            <a href="{{ $actionHref }}" class="ui-link mt-4 inline-flex items-center gap-2 text-sm">
                {{ $actionLabel }}
                <svg class="shrink-0" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 12h14" />
                    <path d="m13 6 6 6-6 6" />
                </svg>
            </a>
        </div>
    </div>

    <div class="border-t border-line">
        <div class="mx-auto flex max-w-content flex-col gap-2 px-5 py-5 text-xs text-subtle sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <span>© {{ now()->year }} {{ config('app.name') }}.</span>
            <span class="flex items-center gap-2">
                {{ __('Focused by design') }}
                <span aria-hidden="true">·</span>
                {{ __('Accessible by default') }}
            </span>
        </div>
    </div>
</footer>
