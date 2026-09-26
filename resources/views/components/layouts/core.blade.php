@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'indexable' => false,
    'livewire' => true,
    'productKey' => null,
])

<x-signal.layouts.core
    :title="$title"
    :description="$description"
    :canonical="$canonical"
    :indexable="$indexable"
    :livewire="$livewire"
    :product-key="$productKey"
>{{ $slot }}</x-signal.layouts.core>
