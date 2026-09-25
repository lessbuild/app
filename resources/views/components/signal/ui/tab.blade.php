@props(['selected' => false])

<button
    type="button"
    role="tab"
    aria-selected="{{ $selected ? 'true' : 'false' }}"
    tabindex="{{ $selected ? '0' : '-1' }}"
    {{ $attributes->class([
        'inline-flex min-h-10 items-center justify-center rounded-control px-4 py-2 text-sm font-bold text-muted transition hover:bg-surface-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus aria-selected:bg-surface aria-selected:text-primary aria-selected:shadow-soft',
    ]) }}
>
    {{ $slot }}
</button>
