@props(['application'])

@php
    $styles = [
        'violet' => 'bg-primary-soft text-primary dark:bg-primary-soft dark:text-primary',
        'sky' => 'bg-info-soft text-info',
        'amber' => 'bg-warning-soft text-warning',
        'emerald' => 'bg-success-soft text-success',
    ];
    $style = $styles[$application->accent] ?? $styles['violet'];
    $initials = collect(explode(' ', $application->name))->filter()->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->take(2)->implode('');
@endphp

<span {{ $attributes->merge(['class' => 'flex h-9 w-9 shrink-0 items-center justify-center rounded-control text-xs font-bold '.$style]) }} aria-hidden="true">
    {{ $initials }}
</span>
