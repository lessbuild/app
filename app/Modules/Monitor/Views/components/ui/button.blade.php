@props(['type' => 'submit', 'variant' => 'primary', 'size' => 'default', 'href' => null, 'disabled' => false])
@php
    $classes = [
        'ui-btn',
        match ($variant) {
            'secondary' => 'ui-btn-secondary',
            'quiet' => 'ui-btn-quiet',
            'danger' => 'ui-btn-danger',
            'outline' => 'ui-btn-outline',
            'soft' => 'ui-btn-soft',
            default => 'ui-btn-primary',
        },
        'ui-btn-sm' => $size === 'sm',
        'ui-btn-lg' => $size === 'lg',
    ];
@endphp

@if($href !== null)
    <a @if(! $disabled) href="{{ $href }}" @else role="link" aria-disabled="true" tabindex="-1" @endif
        {{ $attributes->except($disabled ? ['tabindex', 'aria-disabled', 'role'] : [])->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
