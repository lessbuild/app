@props(['tone' => 'neutral'])

<x-signal.ui.badge :tone="$tone" {{ $attributes }}>{{ $slot }}</x-signal.ui.badge>
