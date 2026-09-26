@props(['icon' => 'inbox', 'title', 'description'])

<x-signal.ui.empty-state :title="$title" :description="$description" tone="muted" padding="p-6" :shadow="false" {{ $attributes }}>
    <x-slot:illustration>
        <x-monitor::icon :name="$icon" class="h-[19px] w-[19px]" />
    </x-slot:illustration>
    @isset($action)
        <x-slot:action>{{ $action }}</x-slot:action>
    @endisset
</x-signal.ui.empty-state>
