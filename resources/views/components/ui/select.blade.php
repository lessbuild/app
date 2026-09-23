@props([
    'id' => null,
    'name' => null,
])

<x-signal.ui.select :id="$id" :name="$name" {{ $attributes }}>{{ $slot }}</x-signal.ui.select>
