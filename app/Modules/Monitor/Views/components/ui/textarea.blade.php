@props(['name', 'label', 'value' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false, 'sensitive' => false])
@php
    $validationKey = $errorKey ?? $name;
    $controlId = $attributes->get('id', $name);
    $inputValue = $sensitive ? null : old($validationKey, $value);
    $inputValue = is_scalar($inputValue) ? $inputValue : null;
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($errors->has($validationKey) ? ' '.$controlId.'-error' : ''));
@endphp
<x-monitor::ui.field :id="$controlId" :label="$label" :error-key="$validationKey" :description="$description" :hide-label="$hideLabel">
    <textarea id="{{ $controlId }}" name="{{ $name }}" aria-invalid="{{ $errors->has($validationKey) ? 'true' : 'false' }}"
        @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['id', 'aria-describedby', 'aria-invalid'])->class(['ui-input'])->merge(['rows' => 3]) }}>{{ $inputValue }}</textarea>
</x-monitor::ui.field>
