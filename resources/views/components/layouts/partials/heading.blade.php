<x-ui.page-header
    :title="$title ?? null"
    :description="$description ?? null"
    :icon="$icon ?? null"
>
    @isset($buttons)
        <x-slot:actions>{{ $buttons }}</x-slot:actions>
    @endisset
</x-ui.page-header>
