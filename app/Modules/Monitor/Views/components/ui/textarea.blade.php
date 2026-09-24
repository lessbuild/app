@props(['name', 'label', 'value' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false, 'sensitive' => false])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $controlId = $attributes->get('id', $name);
    $inputValue = $sensitive ? null : old($validationKey ?? $name, $value);
    $inputValue = is_scalar($inputValue) ? $inputValue : null;
    $hasError = $validationKey !== null && $errors->has($validationKey);
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($hasError ? ' '.$controlId.'-error' : ''));
@endphp
<x-signal.ui.field :id="$controlId" :name="$name" :label="$label" :error-key="$validationKey" :required="$attributes->has('required')" :description="$description" :hide-label="$hideLabel" :show-errors="$validationKey !== null">
    <x-signal.ui.textarea id="{{ $controlId }}" name="{{ $name }}" :value="$inputValue" :restore="false" aria-invalid="{{ $hasError ? 'true' : 'false' }}" :aria-describedby="$describedBy !== '' ? $describedBy : null"
        {{ $attributes->except(['id', 'aria-describedby', 'aria-invalid'])->merge(['rows' => 3]) }} />
</x-signal.ui.field>
