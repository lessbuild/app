@props([
    'href',
    'variant' => 'primary',
    'size' => 'md',
])

<x-signal.ui.link :href="$href" :variant="$variant" :size="$size" {{ $attributes }}>{{ $slot }}</x-signal.ui.link>
