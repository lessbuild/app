@props([
    'value',
    'label',
    'max' => 100,
    'role' => 'progressbar',
    'barClass' => null,
])

@php($maximum = is_numeric($max) ? max(1, (float) $max) : 100)
@php($current = is_numeric($value) ? min($maximum, max(0, (float) $value)) : 0)
@php($percentage = round(($current / $maximum) * 100, 2))
@php($progressRole = in_array($role, ['progressbar', 'meter'], true) ? $role : 'progressbar')

<div
    role="{{ $progressRole }}"
    aria-label="{{ $label }}"
    aria-valuemin="0"
    aria-valuemax="{{ $maximum }}"
    aria-valuenow="{{ $current }}"
    {{ $attributes->class(['ui-progress']) }}
>
    <span @class([$barClass]) style="width: {{ $percentage }}%"></span>
</div>
