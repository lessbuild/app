@props(['tone' => 'default'])

<x-signal.ui.card :tone="$tone" {{ $attributes }}>{{ $slot }}</x-signal.ui.card>
