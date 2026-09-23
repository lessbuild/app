@props(['name', 'label', 'value' => null, 'options' => [], 'placeholder' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false, 'restore' => true])
@php
    $validationKey = $errorKey === false ? null : ($errorKey ?? $name);
    $controlId = $attributes->get('id', $name);
    $selectedValue = $restore ? old($validationKey ?? $name, $value) : $value;
    $selectedValue = is_scalar($selectedValue) ? (string) $selectedValue : '';
    $hasError = $validationKey !== null && $errors->has($validationKey);
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($hasError ? ' '.$controlId.'-error' : ''));
@endphp
<x-monitor::ui.field :id="$controlId" :label="$label" :error-key="$validationKey" :description="$description" :hide-label="$hideLabel">
    <select id="{{ $controlId }}" name="{{ $name }}" aria-invalid="{{ $hasError ? 'true' : 'false' }}"
        @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['id', 'aria-describedby', 'aria-invalid'])->class(['ui-input']) }}>
        @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $key => $optionLabel)
            <option value="{{ $key }}" @selected($selectedValue === (string) $key)>{{ $optionLabel }}</option>
        @endforeach
    </select>
</x-monitor::ui.field>
