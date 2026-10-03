@props([
    'title',
    'description' => null,
])

{{-- Personal settings pages; the topbar's second row lists the sections. --}}
<x-signal.layouts.app :title="$title" :description="$description">
    <x-signal.ui.page-header :eyebrow="__('Your settings')" :title="$title" :description="$description" class="mb-0 sm:mb-0" />

    {{ $slot }}
</x-signal.layouts.app>
