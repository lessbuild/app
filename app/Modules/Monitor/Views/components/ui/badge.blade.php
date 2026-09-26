@props(['tone' => 'slate'])

@php
    $signalTone = match ($tone) {
        'primary', 'violet' => 'accent',
        'sky', 'info' => 'info',
        'green', 'emerald', 'success' => 'success',
        'amber', 'warning' => 'warning',
        'red', 'danger' => 'danger',
        default => 'neutral',
    };
@endphp

<x-signal.ui.badge :tone="$signalTone" {{ $attributes }}>{{ $slot }}</x-signal.ui.badge>
