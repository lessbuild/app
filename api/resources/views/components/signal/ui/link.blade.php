@props([
    'href',
    'variant' => 'primary',
    'size' => 'md',
    'layout' => 'inline',
])

@php
    $variants = [
        'primary' => 'font-extrabold text-primary hover:bg-primary-soft',
        'muted' => 'font-bold text-muted hover:bg-surface-muted hover:text-ink',
        'quiet' => 'font-semibold text-subtle hover:text-ink',
    ];
    $sizes = [
        'sm' => 'min-h-9 px-3 text-sm',
        'md' => 'min-h-10 px-3 text-sm',
        'inline' => 'min-h-0 px-0 text-sm',
        'none' => '',
    ];
    $layouts = [
        'inline' => 'inline-flex items-center gap-2',
        'stack' => 'flex flex-col items-center',
        'block' => 'flex items-center',
    ];
@endphp

<a href="{{ $href }}" {{ $attributes->class([
    'ui-link rounded-control transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
    $layouts[$layout] ?? $layouts['inline'],
    $variants[$variant] ?? $variants['primary'],
    $sizes[$size] ?? $sizes['md'],
]) }}>{{ $slot }}</a>
