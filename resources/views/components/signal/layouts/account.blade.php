@props(['title', 'description' => null, 'user' => null])
@php($accountUser = $user ?? auth('platform')->user())

<x-signal.layouts.core :title="$title" :description="$description" product-key="core" :livewire="false">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    @if ($accountUser)
        <x-signal.layouts.topbar product-key="core" :navigation="[]" :brand-url="route('core.entry')"
            :projects-url="route('core.home')" :account-user="$accountUser" logout-route="platform.logout"
            :show-notifications="false" :show-project-context="false" :show-environment-context="false" />
    @else
        <x-signal.blocks.public-navigation />
    @endif
    <main id="main-content" tabindex="-1" class="ui-layout-gutter mx-auto w-full max-w-content space-y-6 py-8">
        <x-signal.ui.page-header :eyebrow="__('Data and privacy')" :title="$title" :description="$description" />
        {{ $slot }}
    </main>
</x-signal.layouts.core>
