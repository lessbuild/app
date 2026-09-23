@props([
    'id' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
])

@php($id = $id ?: $name)
@php($value = $name ? old($name, $value) : $value)

<input
    @if ($id) id="{{ $id }}" @endif
    @if ($name) name="{{ $name }}" @endif
    type="{{ in_array($type, ['text', 'search', 'email', 'password', 'number', 'url', 'tel', 'date', 'time', 'datetime-local', 'hidden'], true) ? $type : 'text' }}"
    @if ($value !== null) value="{{ $value }}" @endif
    {{ $attributes->class(['ui-input']) }}
>
