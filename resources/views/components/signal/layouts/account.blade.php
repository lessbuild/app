@props([
    'title',
    'account',
    'description' => null,
])

{{-- Account-level pages. The sidebar (ShellComposer) lists the account's sections. --}}
<x-signal.layouts.app :title="$title" :description="$description">
    <x-signal.ui.page-header :eyebrow="$account->name" :title="$title" :description="$description" class="mb-0 sm:mb-0" />

    {{ $slot }}
</x-signal.layouts.app>
