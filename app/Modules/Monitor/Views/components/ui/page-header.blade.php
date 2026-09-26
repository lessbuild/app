@props(['eyebrow' => null, 'eyebrowIcon' => null, 'title', 'description' => null])

<x-signal.ui.page-header :eyebrow="$eyebrow" :eyebrow-icon="$eyebrowIcon" :title="$title" :description="$description" {{ $attributes }}>
    @isset($leading)
        <x-slot:leading>{{ $leading }}</x-slot:leading>
    @endisset
    @isset($metadata)
        <x-slot:metadata><div {{ $metadata->attributes }}>{{ $metadata }}</div></x-slot:metadata>
    @endisset
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-signal.ui.page-header>
