@props([
    'id' => null,
    'name',
    'value' => '1',
    'checked' => false,
])

@php($id = $id ?: $name)

<label class="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-ink">
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="checkbox"
        value="{{ $value }}"
        @checked(old($name, $checked))
        {{ $attributes->class(['h-4 w-4 rounded border-line accent-[var(--ui-primary)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus']) }}
    >
    <span>{{ $slot }}</span>
</label>
