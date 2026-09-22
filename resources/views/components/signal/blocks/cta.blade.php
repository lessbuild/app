@props([
    'eyebrow',
    'title',
    'copy',
    'href',
    'label',
])

<section {{ $attributes->class(['ui-emphasis', 'relative', 'overflow-hidden', 'rounded-panel', 'p-6', 'sm:p-10']) }}>
    <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-primary/30 blur-3xl" aria-hidden="true"></div>

    <div class="relative flex flex-col justify-between gap-7 md:flex-row md:items-end">
        <div class="max-w-2xl">
            <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-brand-300">{{ $eyebrow }}</p>
            <h2 class="mt-4 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ $title }}</h2>
            <p class="ui-emphasis-muted mt-4 max-w-xl leading-7">{{ $copy }}</p>
        </div>

        <a href="{{ $href }}" class="ui-btn ui-btn-primary ui-btn-lg shrink-0">
            {{ $label }}
            <svg class="shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14" />
                <path d="m13 6 6 6-6 6" />
            </svg>
        </a>
    </div>
</section>
