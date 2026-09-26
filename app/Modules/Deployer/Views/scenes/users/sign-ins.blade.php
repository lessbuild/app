<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to account')"
        :route="route('account.index')"
    />

    <x-signal.ui.page-header
        :title="__('Sign-in history')"
        :description="__('Review successful password and social sign-ins retained for account security.')"
    />

    <x-signal.ui.local-nav class="mt-6" :label="__('Sign-in history sections')">
        <a href="#sign-in-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#sign-in-filters" class="ui-local-nav__link">{{ __('Filters') }}</a>
        <a href="#sign-in-history" class="ui-local-nav__link">{{ __('History') }}</a>
    </x-signal.ui.local-nav>

    @include('components.scenes.users.sign-ins-content')
</x-layouts.app>
