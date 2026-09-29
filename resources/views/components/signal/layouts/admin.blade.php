@props([
    'title',
    'description' => null,
])

{{-- Platform admin pages; the topbar's second row lists the sections. --}}
<x-signal.layouts.app :title="$title" :description="$description">
    <x-signal.ui.page-header :eyebrow="__('Platform admin')" :title="$title" :description="$description" class="mb-0 sm:mb-0">
        <x-slot:actions>{{ $actions ?? '' }}</x-slot:actions>
    </x-signal.ui.page-header>
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    {{ $slot }}
</x-signal.layouts.app>
