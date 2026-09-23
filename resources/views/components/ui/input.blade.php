@props([
    'id' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
])

<x-signal.ui.input :id="$id" :name="$name" :type="$type" :value="$value" {{ $attributes }} />
