{{-- Rules, destinations, on-call, maintenance and the noise report share the Alerts section. --}}
<nav aria-label="{{ __('Alerts') }}" class="flex flex-wrap gap-2">
    @foreach (['monitoring.rules' => __('Rules'), 'monitoring.destinations' => __('Destinations'), 'monitoring.on-call' => __('On-call'), 'monitoring.maintenance' => __('Maintenance'), 'monitoring.noise' => __('Noise')] as $route => $label)
        <x-signal.ui.button :href="route($route, $project)" :variant="request()->routeIs($route.'*') ? 'soft' : 'quiet'" size="sm" :aria-current="request()->routeIs($route.'*') ? 'page' : null">{{ $label }}</x-signal.ui.button>
    @endforeach
</nav>
