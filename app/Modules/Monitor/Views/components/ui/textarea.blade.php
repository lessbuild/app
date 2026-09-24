@props(['name', 'label', 'value' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false, 'sensitive' => false])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $controlId = $attributes->get('id', $name);
    $inputValue = $sensitive ? null : old($validationKey ?? $name, $value);
    $inputValue = is_scalar($inputValue) ? $inputValue : null;
@endphp
<x-signal.ui.textarea-field :id="$controlId" :name="$name" :label="$label" :value="$inputValue" :restore="false" :error-key="$errorKey === false ? false : $validationKey" :required="$attributes->has('required')" :description="$description" :hide-label="$hideLabel" :show-errors="$validationKey !== null"
    {{ $attributes->except(['id', 'required'])->merge(['rows' => 3]) }} />
