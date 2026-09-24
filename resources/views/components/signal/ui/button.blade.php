@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
    'size' => 'default',
    'disabled' => false,
])

@php($variants = ['primary', 'secondary', 'ghost', 'quiet', 'danger', 'inverse', 'outline', 'soft'])
@php($variant = in_array($variant, $variants, true) ? $variant : 'secondary')
@php($signalVariant = match ($variant) {
    'ghost' => 'quiet',
    'inverse' => 'secondary',
    default => $variant,
})
@php($classes = ['ui-btn', 'ui-btn-'.$signalVariant, 'ui-btn-sm' => $size === 'sm', 'ui-btn-lg' => $size === 'lg'])

@if ($href !== null && $disabled)
    <a role="link" aria-disabled="true" tabindex="-1" {{ $attributes->except(['role', 'aria-disabled', 'tabindex'])->class($classes) }}>{{ $slot }}</a>
@elseif ($href !== null)
    <a href="{{ htmlspecialchars_decode($href, ENT_QUOTES) }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button' }}" @disabled($disabled) {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
