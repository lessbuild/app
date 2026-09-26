@props([
    'id',
    'route',
    'title',
    'description',
])

<x-signal.overlays.delete-confirmation :id="$id" :route="$route" :title="$title" :description="$description" {{ $attributes }} />
