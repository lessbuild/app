@props(['summary', 'workspace'])

@if(in_array($summary['state'] ?? null, ['warning', 'limit', 'unavailable'], true))
    @php($atLimit = $summary['state'] === 'limit')
    @php($unavailable = $summary['state'] === 'unavailable')
    <x-monitor::ui.alert role="alert" :tone="$unavailable ? 'info' : ($atLimit ? 'danger' : 'warning')" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 gap-3">
            <x-monitor::icon name="alert" class="h-5 w-5 shrink-0" />
            <div class="min-w-0">
                <h2 class="text-sm font-bold">{{ $unavailable ? 'Monitor plan could not be verified' : ($atLimit ? 'Monthly event limit reached' : 'Monthly event usage is at '.$summary['percentage'].'%') }}</h2>
                <p class="mt-1 text-xs leading-5 text-muted">{{ $unavailable ? 'Telemetry intake is paused until the workspace subscription is reconciled in Core. Existing encrypted deliveries remain retryable.' : number_format($summary['event_count']).' of '.number_format($summary['event_limit']).' events used. '.($atLimit ? 'New deliveries remain encrypted and retryable until you upgrade.' : 'Review your plan before the allowance is reached.') }}</p>
            </div>
        </div>
        @can('billing', $workspace)
            <x-monitor::ui.button :href="route('monitor.settings.billing')" variant="secondary" size="sm" class="shrink-0">Review plans <x-monitor::icon name="arrow-up-right" class="h-3.5 w-3.5" /></x-monitor::ui.button>
        @endcan
    </x-monitor::ui.alert>
@endif
