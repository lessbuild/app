@props([
    'id' => null,
    'name' => null,
    'value' => null,
])

<x-signal.ui.textarea :id="$id" :name="$name" :value="$value" {{ $attributes }}>{{ $slot }}</x-signal.ui.textarea>
