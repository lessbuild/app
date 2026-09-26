@props([
    'eyebrow' => null,
    'title' => null,
    'description' => null,
    'icon' => null,
])

<x-signal.ui.page-header
    :eyebrow="$eyebrow"
    :title="$title"
    :description="$description"
    :icon="$icon"
>
    @isset($buttons)
        <x-slot:actions>{{ $buttons }}</x-slot:actions>
    @endisset
</x-signal.ui.page-header>
