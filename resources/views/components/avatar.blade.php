@props(['name'])

@php
    $words = preg_split('/\s+/u', trim(strip_tags((string) $name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = collect($words)
        ->take(2)
        ->map(fn (string $word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))
        ->implode('');
@endphp

<span
    {{ $attributes->class('inline-flex shrink-0 items-center justify-center bg-slate-800 font-semibold uppercase text-white') }}
    aria-hidden="true"
>{{ $initials !== '' ? $initials : '?' }}</span>
