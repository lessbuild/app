@props([
    'id' => null,
    'name',
    'value' => '1',
    'checked' => false,
    'uncheckedValue' => null,
    'restore' => true,
])

@php($id = $id ?: $name)
@php($checked = $restore ? old($name, $checked) : $checked)

@if ($uncheckedValue !== null)
    <input type="hidden" name="{{ $name }}" value="{{ $uncheckedValue }}">
@endif
<label class="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-ink">
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="checkbox"
        value="{{ $value }}"
        @checked($checked)
        {{ $attributes->class(['h-4 w-4 rounded border-line accent-[var(--ui-primary)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus']) }}
    >
    <span>{{ $slot }}</span>
</label>
