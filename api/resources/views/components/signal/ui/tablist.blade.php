@props(['label'])

<div
    role="tablist"
    aria-label="{{ $label }}"
    {{ $attributes->class(['inline-flex flex-wrap gap-1 rounded-control border border-line bg-surface-muted p-1']) }}
>
    {{ $slot }}
</div>
