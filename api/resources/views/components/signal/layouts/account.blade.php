@props([
    'title',
    'account',
    'description' => null,
])

{{-- Account-level pages. The sidebar (ShellComposer) lists the account's sections; an optional actions slot fills the header's buttons. --}}
<x-signal.layouts.app :title="$title" :description="$description">
    <x-signal.ui.page-header :eyebrow="$account->name" :title="$title" :description="$description" class="mb-0 sm:mb-0">
        @isset($actions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endisset
    </x-signal.ui.page-header>

    {{ $slot }}
</x-signal.layouts.app>
