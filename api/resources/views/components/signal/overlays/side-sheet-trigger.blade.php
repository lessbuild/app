@props([
    'sheet',
    'variant' => 'secondary',
    'size' => 'default',
    'type' => 'button',
])

<x-signal.ui.button
    :variant="$variant"
    :size="$size"
    :type="$type"
    {{ $attributes->merge([
        'data-sheet-open' => $sheet,
        'aria-controls' => $sheet,
        'aria-expanded' => 'false',
        'aria-haspopup' => 'dialog',
    ]) }}
>{{ $slot }}</x-signal.ui.button>
