@props([
    'label',
    'name',
    'id' => null,
    'description' => null,
    'required' => false,
    'showErrors' => true,
])

<x-signal.ui.field :label="$label" :name="$name" :id="$id" :description="$description" :required="$required" :show-errors="$showErrors" {{ $attributes }}>{{ $slot }}</x-signal.ui.field>
