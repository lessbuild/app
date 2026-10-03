@props([
    'id' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
    'restore' => true,
])

@php($id = $id ?: (in_array($type, ['hidden', 'checkbox', 'radio'], true) ? null : $name))
@php($value = $restore && $name && $type !== 'password' ? old($name, $value) : $value)
@php($controlClasses = match ($type) {
    'hidden' => [],
    'checkbox', 'radio' => ['ui-check'],
    default => ['ui-input'],
})

<input
    @if ($id) id="{{ $id }}" @endif
    @if ($name) name="{{ $name }}" @endif
    type="{{ in_array($type, ['text', 'search', 'email', 'password', 'number', 'url', 'tel', 'date', 'time', 'datetime-local', 'file', 'checkbox', 'radio', 'hidden'], true) ? $type : 'text' }}"
    @if ($value !== null) value="{{ $value }}" @endif
    {{ $controlClasses ? $attributes->class($controlClasses) : $attributes }}
>
