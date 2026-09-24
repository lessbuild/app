@props([
    'name',
    'label',
    'id' => null,
    'errorKey' => null,
    'description' => null,
    'hideLabel' => false,
    'required' => false,
    'showErrors' => true,
])

@php($controlId = $id ?: $name)
@php($validationKey = $errorKey === false ? null : ($errorKey ?? $name))
@php($hasError = $validationKey !== null && $errors->has($validationKey))
@php($describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($hasError ? ' '.$controlId.'-error' : '')))

<x-signal.ui.field :id="$controlId" :name="$name" :label="$label" :error-key="$errorKey === false ? false : $validationKey" :description="$description" :hide-label="$hideLabel" :required="$required" :show-errors="$showErrors && $validationKey !== null">
    <x-signal.ui.select :id="$controlId" :name="$name" :required="$required"
        aria-invalid="{{ $hasError ? 'true' : 'false' }}" :aria-describedby="$describedBy !== '' ? $describedBy : null"
        {{ $attributes->except(['aria-describedby', 'aria-invalid', 'required']) }}>{{ $slot }}</x-signal.ui.select>
</x-signal.ui.field>
