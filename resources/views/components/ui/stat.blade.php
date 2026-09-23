@props([
    'label',
    'value',
    'description' => null,
])

<x-signal.ui.stat :label="$label" :value="$value" :description="$description" {{ $attributes }} />
