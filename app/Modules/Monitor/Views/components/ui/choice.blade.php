@props(['id', 'name', 'label', 'value' => 1, 'checked' => false, 'type' => 'checkbox', 'description' => null, 'card' => false, 'errorKey' => null])

<x-signal.ui.choice :id="$id" :name="$name" :label="$label" :value="$value" :checked="$checked" :type="$type" :description="$description" :card="$card" :error-key="$errorKey" :required="$attributes->has('required')" {{ $attributes->except('required') }}>
    {{ $slot }}
</x-signal.ui.choice>
