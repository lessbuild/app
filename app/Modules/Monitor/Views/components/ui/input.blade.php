@props(['name', 'label', 'type' => 'text', 'value' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $inputValue = old($validationKey ?? $name, $value);
    $inputValue = $type === 'password' ? null : $inputValue;
    $inputValue = is_scalar($inputValue) ? $inputValue : null;
    $controlId = $attributes->get('id', $name);
    $hasError = $validationKey !== null && $errors->has($validationKey);
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($hasError ? ' '.$controlId.'-error' : ''));
@endphp
<x-signal.ui.field :id="$controlId" :name="$name" :label="$label" :error-key="$validationKey" :required="$attributes->has('required')" :description="$description" :hide-label="$hideLabel" :show-errors="$validationKey !== null">
    <x-signal.ui.input id="{{ $controlId }}" name="{{ $name }}" type="{{ $type }}" :value="$inputValue" :restore="false"
        aria-invalid="{{ $hasError ? 'true' : 'false' }}" :aria-describedby="$describedBy !== '' ? $describedBy : null"
        {{ $attributes->except(['id', 'aria-describedby', 'aria-invalid']) }} />
</x-signal.ui.field>
