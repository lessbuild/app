@props([
    'label',
    'name',
    'id' => null,
    'description' => null,
    'required' => false,
    'showErrors' => true,
])

@php($id = $id ?: $name)

<div {{ $attributes->class(['grid gap-2']) }}>
    <label for="{{ $id }}" class="text-sm font-bold text-ink">
        {{ $label }}
        @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    @if ($description)
        <p id="{{ $id }}-help" class="text-xs leading-5 text-muted">{{ $description }}</p>
    @endif
    @if ($showErrors)
        <x-forms.errors :name="$name" />
    @endif
</div>
