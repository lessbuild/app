@props([
    'title' => null,
    'description' => null,
    'icon' => 'information-circle',
])

<x-signal.ui.empty-state :title="$title" :description="$description" :icon="$icon" {{ $attributes }}>
    {{ $slot }}
    @isset($action)
        <x-slot:action>{{ $action }}</x-slot:action>
    @endisset
</x-signal.ui.empty-state>
