@props([
    'name',
    'label',
    'id' => null,
    'value' => null,
    'errorKey' => null,
    'description' => null,
    'hideLabel' => false,
    'required' => false,
    'restore' => true,
    'showErrors' => true,
])

@php($controlId = $id ?: $name)
@php($validationKey = $errorKey === false ? null : ($errorKey ?? $name))
@php($hasError = $validationKey !== null && $errors->has($validationKey))
@php($describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($hasError ? ' '.$controlId.'-error' : '')))

<x-signal.ui.field :id="$controlId" :name="$name" :label="$label" :error-key="$errorKey === false ? false : $validationKey" :description="$description" :hide-label="$hideLabel" :required="$required" :show-errors="$showErrors && $validationKey !== null">
    <x-signal.ui.textarea :id="$controlId" :name="$name" :value="$value" :restore="$restore" :required="$required"
        aria-invalid="{{ $hasError ? 'true' : 'false' }}" :aria-describedby="$describedBy !== '' ? $describedBy : null"
        {{ $attributes->except(['aria-describedby', 'aria-invalid', 'required']) }} />
</x-signal.ui.field>
