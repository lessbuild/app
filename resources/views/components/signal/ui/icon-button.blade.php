@props([
    'label',
    'href' => null,
    'type' => 'button',
])

@if ($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" {{ $attributes->class(['ui-icon-btn']) }}>{{ $slot }}</a>
@else
    <button type="{{ in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button' }}" aria-label="{{ $label }}" {{ $attributes->class(['ui-icon-btn']) }}>{{ $slot }}</button>
@endif
