@props([
    'label',
    'href' => null,
    'type' => 'button',
])

<x-signal.ui.icon-button :label="$label" :href="$href" :type="$type" {{ $attributes }}>{{ $slot }}</x-signal.ui.icon-button>
