@props([
    'id' => null,
    'name' => null,
])

@php($id = $id ?: $name)

<select
    @if ($id) id="{{ $id }}" @endif
    @if ($name) name="{{ $name }}" @endif
    {{ $attributes->class(['ui-input']) }}
>
    {{ $slot }}
</select>
