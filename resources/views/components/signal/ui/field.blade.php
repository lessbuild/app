@props([
    'label',
    'name' => null,
    'id' => null,
    'description' => null,
    'required' => false,
    'showErrors' => true,
    'errorKey' => null,
    'hideLabel' => false,
])

@php($id = $id ?: $name)
@php($fieldName = $errorKey ?? $name)

<div {{ $attributes->class(['grid min-w-0 gap-2']) }}>
    <label for="{{ $id }}" @class(['ui-label', 'sr-only' => $hideLabel, 'wrap-anywhere' => ! $hideLabel])>
        {{ $label }}
        @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    @if ($description)
        <p id="{{ $id }}-help" class="ui-help wrap-anywhere">{{ $description }}</p>
    @endif
    @if ($showErrors && is_string($fieldName) && $fieldName !== '')
        <x-forms.errors :name="$fieldName" :id="$id ? $id.'-error' : null" />
    @endif
</div>
