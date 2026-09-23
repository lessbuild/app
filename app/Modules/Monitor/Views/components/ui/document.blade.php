@props(['title' => config('app.name'), 'csrf' => false, 'noindex' => true])

<x-layouts.core :title="$title" :livewire="false" product-key="monitor">
    {{ $slot }}
</x-layouts.core>
