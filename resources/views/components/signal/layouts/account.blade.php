@props([
    'title',
    'account',
    'description' => null,
])

@php($sections = array_filter([
    'account.members' => __('Members'),
    'account.audit-log' => auth()->user()?->can('viewAuditLog', $account) ? __('Audit log') : null,
]))

{{-- Account-level pages; Phase 2 moves these links into the account sidebar. --}}
<x-signal.layouts.app :title="$title" :description="$description">
    <x-signal.ui.page-header :eyebrow="$account->name" :title="$title" :description="$description" class="mb-0 sm:mb-0" />

    @if (count($sections) > 1)
        <x-signal.ui.local-nav :label="__('Account sections')">
            @foreach ($sections as $route => $label)
                <a href="{{ route($route) }}" class="ui-local-nav__link" @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </x-signal.ui.local-nav>
    @endif

    {{ $slot }}
</x-signal.layouts.app>
