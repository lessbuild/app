@props([
    'id' => null,
    'name' => null,
    'value' => null,
    'restore' => true,
])

@php($id = $id ?: $name)
@php($value = $restore && $name ? old($name, $value) : $value)

<textarea
    @if ($id) id="{{ $id }}" @endif
    @if ($name) name="{{ $name }}" @endif
    {{ $attributes->class(['ui-input min-h-28']) }}
>@if ($value !== null){{ $value }}@else{{ $slot }}@endif</textarea>
