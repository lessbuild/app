@props([
    'label',
    'type' => 'button',
])

<button
    type="{{ in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button' }}"
    aria-label="{{ $label }}"
    {{ $attributes }}
></button>
