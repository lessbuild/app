{{-- The metrics explorer and dashboards share the Metrics section. --}}
<nav aria-label="{{ __('Metrics') }}" class="flex flex-wrap gap-2">
    @foreach (['monitoring.metrics' => __('Explorer'), 'monitoring.dashboards' => __('Dashboards')] as $route => $label)
        <x-signal.ui.button :href="route($route, $project)" :variant="request()->routeIs($route.'*') ? 'soft' : 'quiet'" size="sm" :aria-current="request()->routeIs($route.'*') ? 'page' : null">{{ $label }}</x-signal.ui.button>
    @endforeach
</nav>
