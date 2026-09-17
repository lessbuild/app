@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
])

@php($variants = ['primary', 'secondary', 'ghost', 'danger', 'inverse'])
@php($variant = in_array($variant, $variants, true) ? $variant : 'secondary')
@php($buttonClasses = 'button button--'.$variant)

@if ($href)
    <a href="{{ htmlspecialchars_decode($href, ENT_QUOTES) }}" {{ $attributes->merge(['class' => $buttonClasses]) }}>{{ $slot }}</a>
@elseif ($type === 'submit')
    <button type="submit" {{ $attributes->merge(['class' => $buttonClasses]) }}>{{ $slot }}</button>
@elseif ($type === 'reset')
    <button type="reset" {{ $attributes->merge(['class' => $buttonClasses]) }}>{{ $slot }}</button>
@else
    <button type="button" {{ $attributes->merge(['class' => $buttonClasses]) }}>{{ $slot }}</button>
@endif
