@props(['name', 'label', 'value' => null, 'options' => [], 'placeholder' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false, 'restore' => true])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $controlId = $attributes->get('id', $name);
    $selectedValue = $restore ? old($validationKey ?? $name, $value) : $value;
    $selectedValue = is_scalar($selectedValue) ? (string) $selectedValue : '';
@endphp
<x-signal.ui.select-field :id="$controlId" :name="$name" :label="$label" :error-key="$errorKey === false ? false : $validationKey" :required="$attributes->has('required')" :description="$description" :hide-label="$hideLabel" :show-errors="$validationKey !== null"
    {{ $attributes->except(['id', 'required']) }}>
        @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $key => $optionLabel)
            <option value="{{ $key }}" @selected($selectedValue === (string) $key)>{{ $optionLabel }}</option>
        @endforeach
    </x-signal.ui.select-field>
