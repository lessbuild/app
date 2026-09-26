@props([
    'id',
    'title',
    'description' => null,
    'open' => false,
    'bodyClass' => 'px-5 py-5 sm:px-6',
])

<x-signal.overlays.modal :id="$id" :title="$title" :description="$description" :open="$open" :body-class="$bodyClass" {{ $attributes }}>{{ $slot }}</x-signal.overlays.modal>
