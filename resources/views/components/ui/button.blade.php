@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
])

<x-signal.ui.button :variant="$variant" :href="$href" :type="$type" {{ $attributes }}>{{ $slot }}</x-signal.ui.button>
