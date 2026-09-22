<x-ui.empty-state
    :title="$title ?? null"
    :description="$description ?? null"
>
    @isset($button)
        <x-slot:action>{{ $button }}</x-slot:action>
    @endisset
</x-ui.empty-state>
