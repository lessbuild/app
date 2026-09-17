@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
])

@php($variants = ['primary', 'secondary', 'ghost', 'danger', 'inverse'])
@php($variant = in_array($variant, $variants, true) ? $variant : 'secondary')
@php($buttonClasses = 'button button--'.$variant)

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $buttonClasses]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $buttonClasses]) }}>{{ $slot }}</button>
@endif
