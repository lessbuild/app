@props(['name', 'label', 'type' => 'text', 'value' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $inputValue = old($validationKey ?? $name, $value);
    $inputValue = $type === 'password' ? null : $inputValue;
    $inputValue = is_scalar($inputValue) ? $inputValue : null;
    $controlId = $attributes->get('id', $name);
@endphp
<x-signal.ui.input-field :id="$controlId" :name="$name" :label="$label" :type="$type" :value="$inputValue" :restore="false"
    :error-key="$errorKey === false ? false : $validationKey" :required="$attributes->has('required')" :description="$description" :hide-label="$hideLabel" :show-errors="$validationKey !== null"
    {{ $attributes->except(['id', 'required']) }} />
