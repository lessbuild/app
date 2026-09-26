@props([
    'href',
    'label',
    'description',
])

<a href="{{ $href }}" class="group flex min-h-11 items-center justify-between gap-3 rounded-control px-3 py-2 transition hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus">
    <span class="min-w-0">
        <span class="block text-sm font-bold text-ink">{{ $label }}</span>
        <span class="mt-0.5 block text-xs leading-5 text-muted">{{ $description }}</span>
    </span>
    <span class="shrink-0 text-primary transition-transform group-hover:translate-x-0.5" aria-hidden="true">↗</span>
</a>
