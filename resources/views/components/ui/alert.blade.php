@props(['tone' => 'info'])

<x-signal.ui.alert :tone="$tone" {{ $attributes }}>{{ $slot }}</x-signal.ui.alert>
