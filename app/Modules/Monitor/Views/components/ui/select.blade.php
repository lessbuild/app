@props(['name', 'label', 'value' => null, 'options' => [], 'placeholder' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false, 'restore' => true])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $controlId = $attributes->get('id', $name);
    $selectedValue = $restore ? old($validationKey ?? $name, $value) : $value;
    $selectedValue = is_scalar($selectedValue) ? (string) $selectedValue : '';
    $hasError = $validationKey !== null && $errors->has($validationKey);
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($hasError ? ' '.$controlId.'-error' : ''));
@endphp
<x-signal.ui.field :id="$controlId" :name="$name" :label="$label" :error-key="$validationKey" :required="$attributes->has('required')" :description="$description" :hide-label="$hideLabel" :show-errors="$validationKey !== null">
    <x-signal.ui.select id="{{ $controlId }}" name="{{ $name }}" aria-invalid="{{ $hasError ? 'true' : 'false' }}" :aria-describedby="$describedBy !== '' ? $describedBy : null"
        {{ $attributes->except(['id', 'aria-describedby', 'aria-invalid']) }}>
        @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $key => $optionLabel)
            <option value="{{ $key }}" @selected($selectedValue === (string) $key)>{{ $optionLabel }}</option>
        @endforeach
    </x-signal.ui.select>
</x-signal.ui.field>
