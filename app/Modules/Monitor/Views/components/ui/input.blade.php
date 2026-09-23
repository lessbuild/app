@props(['name', 'label', 'type' => 'text', 'value' => null, 'errorKey' => null, 'description' => null, 'hideLabel' => false])
@php
    $validationKey = $errorKey ?? $name;
    $inputValue = old($validationKey, $value);
    $inputValue = is_scalar($inputValue) ? $inputValue : null;
    $controlId = $attributes->get('id', $name);
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($description !== null ? ' '.$controlId.'-help' : '').($errors->has($validationKey) ? ' '.$controlId.'-error' : ''));
@endphp
<x-monitor::ui.field :id="$controlId" :label="$label" :error-key="$validationKey" :description="$description" :hide-label="$hideLabel">
    <input id="{{ $controlId }}" name="{{ $name }}" type="{{ $type }}"
        @if($type !== 'password') value="{{ $inputValue }}" @endif
        aria-invalid="{{ $errors->has($validationKey) ? 'true' : 'false' }}"
        @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except(['id', 'aria-describedby', 'aria-invalid'])->class(['ui-input']) }}>
</x-monitor::ui.field>
