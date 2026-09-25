@props(['size' => 'sm', 'color' => null])

@php($size = in_array($size, ['sm', 'lg'], true) ? $size : 'sm')

<span
    {{ $attributes->class(['ui-status-dot', 'ui-status-dot-lg' => $size === 'lg']) }}
    @if ($color) style="--ui-status-dot: {{ $color }}" @endif
></span>
