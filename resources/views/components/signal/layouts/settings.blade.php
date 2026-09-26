@props([
    'title',
    'description' => null,
])

@php($sections = [
    'settings.profile' => __('Profile'),
    'settings.security' => __('Security'),
    'settings.sessions' => __('Sessions'),
])

<x-signal.layouts.app :title="$title" :description="$description">
    <x-signal.ui.page-header :eyebrow="__('Your settings')" :title="$title" :description="$description" class="mb-0 sm:mb-0" />

    <x-signal.ui.local-nav :label="__('Settings sections')">
        @foreach ($sections as $route => $label)
            <a href="{{ route($route) }}" class="ui-local-nav__link" @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </x-signal.ui.local-nav>

    {{ $slot }}
</x-signal.layouts.app>
