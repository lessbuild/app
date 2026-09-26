@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
    'size' => 'default',
    'disabled' => false,
])

@php($variants = ['primary', 'secondary', 'ghost', 'quiet', 'danger', 'inverse', 'outline', 'soft', 'link', 'stateful'])
@php($variant = in_array($variant, $variants, true) ? $variant : 'secondary')
@php($signalVariant = match ($variant) {
    'ghost' => 'quiet',
    'inverse' => 'secondary',
    default => $variant,
})
@php($baseClasses = match ($variant) {
    'link' => ['ui-link'],
    'stateful' => ['ui-btn'],
    default => ['ui-btn', 'ui-btn-'.$signalVariant],
})
@php($classes = array_merge($baseClasses, ['ui-btn-sm' => $size === 'sm', 'ui-btn-lg' => $size === 'lg']))

@if ($href !== null && $disabled)
    <a role="link" aria-disabled="true" tabindex="-1" {{ $attributes->except(['role', 'aria-disabled', 'tabindex'])->class($classes) }}>{{ $slot }}</a>
@elseif ($href !== null)
    <a href="{{ htmlspecialchars_decode($href, ENT_QUOTES) }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button' }}" @disabled($disabled) {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
