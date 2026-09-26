<x-layouts.app>
    <x-signal.ui.page-header
        eyebrow="{{ __('Platform diagnostics') }}"
        icon="chip"
        :title="__('System Health')"
        :description="__('Read-only checks for the application runtime, storage, queue, and production automation.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('system-health.report')" variant="secondary">
                {{ __('Download report') }}
            </x-signal.ui.button>
            <x-signal.ui.button :href="route('system-health.index')" variant="primary">
                {{ __('Run checks again') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.local-nav :label="__('System health sections')">
        <a href="#system-health-insights" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#system-health-checks" class="ui-local-nav__link">{{ __('Checks') }}</a>
        <a href="#system-health-help" class="ui-local-nav__link">{{ __('When a check fails') }}</a>
    </x-signal.ui.local-nav>

    @include('system-health._content')
</x-layouts.app>
