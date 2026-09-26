@props(['padding' => 'p-5', 'shadow' => true])

<x-signal.ui.card :padding="$padding" :shadow="$shadow" {{ $attributes }}>{{ $slot }}</x-signal.ui.card>
