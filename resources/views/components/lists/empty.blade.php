<x-ui.empty-state
    class="bg-primary"
    :title="$title ?? null"
    :description="$description ?? null"
>
    @isset($button)
        <x-slot:action>{{ $button }}</x-slot:action>
    @endisset
</x-ui.empty-state>
