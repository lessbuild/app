@props([
    'name',
    'label',
    'id' => null,
    'type' => 'text',
    'value' => null,
    'errorKey' => null,
    'description' => null,
    'hideLabel' => false,
    'required' => false,
    'fieldClass' => null,
    'restore' => true,
    'showErrors' => true,
])

@php($controlId = $id ?: $name)
@php($validationKey = $errorKey === false ? null : ($errorKey ?? $name))
@php($hasError = $validationKey !== null && $errors->has($validationKey))
@php($describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($hasError ? ' '.$controlId.'-error' : '')))

<x-signal.ui.field :id="$controlId" :name="$name" :label="$label" :error-key="$errorKey === false ? false : $validationKey" :description="$description" :hide-label="$hideLabel" :required="$required" :show-errors="$showErrors && $validationKey !== null" :class="$fieldClass">
    <x-signal.ui.input :id="$controlId" :name="$name" :type="$type" :value="$value" :restore="$restore" :required="$required"
        aria-invalid="{{ $hasError ? 'true' : 'false' }}" :aria-describedby="$describedBy !== '' ? $describedBy : null"
        {{ $attributes->except(['aria-describedby', 'aria-invalid', 'required']) }} />
    {{ $slot }}
</x-signal.ui.field>
