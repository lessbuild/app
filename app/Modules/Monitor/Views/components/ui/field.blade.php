@props(['id', 'label', 'errorKey' => null, 'description' => null, 'hideLabel' => false])

<x-signal.ui.field :id="$id" :name="$errorKey === null || $errorKey === false ? null : $errorKey" :label="$label" :error-key="$errorKey === false ? null : $errorKey" :description="$description" :hide-label="$hideLabel" :show-errors="$errorKey !== null && $errorKey !== false" {{ $attributes }}>
    {{ $slot }}
</x-signal.ui.field>
