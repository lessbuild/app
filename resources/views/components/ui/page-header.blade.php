@props([
    'eyebrow' => null,
    'title' => null,
    'description' => null,
    'icon' => null,
])

<x-signal.ui.page-header :eyebrow="$eyebrow" :title="$title" :description="$description" :icon="$icon" {{ $attributes }}>
    {{ $slot }}
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-signal.ui.page-header>
