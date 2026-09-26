@extends('monitor::layouts.app')
@section('title', $alertRule->name)
@section('breadcrumb', 'Alert rules')
@section('content')
<a href="{{ route('monitor.alerts.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Alert rules</a>
<x-monitor::ui.page-header :title="$alertRule->name" :description="$alertRule->environment->application->name.' / '.$alertRule->environment->name.' · '.($alertRule->service ?? 'All services')">
    <x-slot:actions><x-monitor::ui.alert-state :rule="$alertRule" />@can('update', $alertRule)<x-monitor::ui.button :href="route('monitor.alerts.edit', $alertRule)" variant="secondary">Edit rule</x-monitor::ui.button>@endcan</x-slot:actions>
</x-monitor::ui.page-header>
<x-signal.ui.panel as="section" class="space-y-4 p-6">
    <h2 class="text-lg font-bold">{{ $alertRule->metric->label() }} {{ $alertRule->comparisonLabel() }} {{ $alertRule->thresholdValue() }}</h2>
    @if($alertRule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::SloBurnRate)<p class="text-sm text-muted dark:text-subtle">Linked objective: {{ $alertRule->serviceLevelObjective?->name ?? 'Unavailable' }} · The alert evaluates the objective's rolling error budget.</p>@elseif($alertRule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::MetricAnomaly)<p class="text-sm text-muted dark:text-subtle">{{ $alertRule->window_minutes }} minute window · Opens when the latest series value reaches an anomaly score of {{ number_format($alertRule->thresholdValue(), 2) }}× · Baseline requires {{ \App\Modules\Monitor\Services\MetricAnomalyDetector::MINIMUM_BASELINE_POINTS }} prior valid points.</p>@elseif($alertRule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::LogPatternCount)<p class="text-sm text-muted dark:text-subtle">{{ $alertRule->window_minutes }} minute window · Opens at {{ number_format($alertRule->thresholdValue()) }} matches for “{{ $alertRule->match_text }}” · Minimum samples is ignored because zero matches is healthy.</p>@endif
    @if($alertRule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::TelemetryVolume)<p class="text-sm text-muted dark:text-subtle">{{ $alertRule->window_minutes }} minute window · Breaches at {{ number_format($alertRule->thresholdValue()) }} or fewer matching events · Open after {{ $alertRule->trigger_checks }} checks · Recover after {{ $alertRule->recovery_checks }} healthy checks</p>@elseif($alertRule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::TelemetryFreshness)<p class="text-sm text-muted dark:text-subtle">Latest event age threshold {{ number_format($alertRule->thresholdValue()) }} seconds · Open after {{ $alertRule->trigger_checks }} stale checks · Recover after {{ $alertRule->recovery_checks }} fresh checks</p>@elseif(! in_array($alertRule->metric, [\App\Modules\Monitor\Data\Telemetry\AlertMetric::SloBurnRate, \App\Modules\Monitor\Data\Telemetry\AlertMetric::MetricAnomaly, \App\Modules\Monitor\Data\Telemetry\AlertMetric::LogPatternCount], true))<p class="text-sm text-muted dark:text-subtle">{{ $alertRule->window_minutes }} minute window · Minimum {{ number_format($alertRule->minimum_samples) }} eligible samples · Open after {{ $alertRule->trigger_checks }} breaching checks · Recover after {{ $alertRule->recovery_checks }} below-threshold checks</p>@endif
    <x-monitor::ui.alert-observation :observation="$alertRule->observation" />
    <p class="text-xs text-muted dark:text-subtle">Last checked: {{ $alertRule->checked_at ? $alertRule->checked_at->format('Y-m-d H:i:s').' UTC' : 'Not yet' }} · Breach streak {{ $alertRule->breach_streak }} / {{ $alertRule->trigger_checks }} · Recovery streak {{ $alertRule->recovery_streak }} / {{ $alertRule->recovery_checks }}</p>
    <p class="text-xs leading-5 text-muted dark:text-subtle">Source-time windows have a one-minute ingestion delay. Missing or insufficient data resets both streaks and leaves active incidents unresolved. Missed evaluation intervals also reset streaks. Results reflect collected telemetry, not an availability guarantee.</p>
    @if(in_array($alertRule->metric, [\App\Modules\Monitor\Data\Telemetry\AlertMetric::NumericMetric, \App\Modules\Monitor\Data\Telemetry\AlertMetric::MetricAnomaly], true))<p class="text-xs text-muted dark:text-subtle">Series: {{ $alertRule->metricSeries?->name ?? 'Unavailable' }} · {{ $alertRule->metricSeries?->resource_label ?? 'Unavailable' }}@if($alertRule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::NumericMetric) · Calculation: {{ $alertRule->aggregation }} · Freshness: {{ $alertRule->freshness_seconds }} seconds.@endif</p>@endif
    <a href="{{ route('monitor.events.index', ['environment' => $alertRule->environment_id]) }}" class="inline-flex text-xs font-bold text-primary hover:underline dark:text-primary">Investigate environment events →</a>
</x-signal.ui.panel>
<section class="space-y-4"><h2 class="text-lg font-bold">Incident history</h2><x-monitor::ui.incident-list :incidents="$incidents" /></section>
@can('update', $alertRule)
<x-signal.ui.panel as="form" method="POST" action="{{ route('monitor.alerts.destinations', $alertRule) }}" class="space-y-4 p-6">
    @csrf @method('PUT')<x-signal.ui.input type="hidden" name="version" value="{{ $alertRule->state_version }}" :restore="false" />
    <h2 class="text-lg font-bold">Notification routing</h2>
    <p class="text-xs leading-5 text-muted dark:text-subtle">Choose up to five destinations for future incident transitions. Existing incidents are not backfilled. Removing a route prevents queued sends; requests already in flight may finish.</p>
    @php
        $routingWasSubmitted = session()->hasOldInput('opened') || session()->hasOldInput('recovered');
        $selectedDestinations = old('destinations', $routingWasSubmitted ? [] : $routes->pluck('id')->all());
    @endphp
    <fieldset class="space-y-3"><legend class="mb-3 text-xs font-semibold">Destinations</legend>
        @forelse($destinations as $destination)<x-monitor::ui.choice :id="'alert-destination-'.$destination->id" name="destinations[]" :value="$destination->id" :checked="is_array($selectedDestinations) && in_array($destination->id, $selectedDestinations)" :label="$destination->name.($destination->enabled ? '' : ' (paused)')" />
        @empty<p class="text-sm text-muted dark:text-subtle">No destinations configured.</p>@endforelse
    </fieldset>
    @error('destinations')<p class="text-xs text-danger dark:text-danger">{{ $message }}</p>@enderror
    <div class="grid gap-4 sm:grid-cols-2"><x-monitor::ui.select name="opened" label="Incident opened" :value="$routes->isEmpty() ? 1 : (int) $routes->contains(fn ($route) => $route->pivot->opened)" :options="[1 => 'Notify', 0 => 'Do not notify']" /><x-monitor::ui.select name="recovered" label="Incident recovered" :value="$routes->isEmpty() ? 1 : (int) $routes->contains(fn ($route) => $route->pivot->recovered)" :options="[1 => 'Notify', 0 => 'Do not notify']" /></div>
    <div class="flex flex-wrap items-center gap-4"><x-monitor::ui.button>Save routing</x-monitor::ui.button><a href="{{ route('monitor.alert-destinations.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">Manage destinations →</a></div>
</x-signal.ui.panel>
<x-signal.ui.panel as="form" method="POST" action="{{ route('monitor.alerts.escalations', $alertRule) }}" class="space-y-4 p-6">
    @csrf @method('PUT')<x-signal.ui.input type="hidden" name="version" value="{{ $alertRule->state_version }}" :restore="false" />
    <div><h2 class="text-lg font-bold">Escalation policy</h2><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Give unresolved incidents a second line of defense. Each step is queued only after its delay, and recovery cancels any step that has not been sent.</p></div>
    @if($escalationLimit === 0)
        <x-signal.ui.alert as="div" tone="info" class="border-primary/30 bg-primary-soft block p-4 text-xs leading-5 text-primary dark:text-primary"><p class="font-bold">Escalations are available on paid plans.</p><p class="mt-1">Upgrade to Pro or above to notify additional destinations after a delay.</p><a href="{{ route('monitor.settings.billing') }}" class="mt-2 inline-flex font-bold underline">Review plans →</a></x-signal.ui.alert>
    @else
        @php($savedEscalations = $escalations->map(fn ($escalation): array => ['destination_id' => $escalation->alert_destination_id, 'delay_minutes' => $escalation->delay_minutes])->all())
        @php($selectedEscalations = old('escalations', $savedEscalations))
        <div class="space-y-4">
            @for($position = 0; $position < min($escalationLimit ?? 10, 10); $position++)
                @php($step = is_array($selectedEscalations[$position] ?? null) ? $selectedEscalations[$position] : [])
                <x-signal.ui.card as="div" class="shadow-none grid gap-4 p-4 sm:grid-cols-[auto_1fr_1fr] sm:items-end">
                    <p class="text-xs font-bold text-muted dark:text-subtle">Step {{ $position + 1 }}</p>
                    <x-monitor::ui.select :id="'escalation-'.$position.'-destination'" name="escalations[{{ $position }}][destination_id]" :error-key="'escalations.'.$position.'.destination_id'" label="Destination" :value="$step['destination_id'] ?? null" :options="$escalationDestinations->pluck('name', 'id')->all()" placeholder="No escalation" />
                    <x-monitor::ui.input :id="'escalation-'.$position.'-delay'" name="escalations[{{ $position }}][delay_minutes]" :error-key="'escalations.'.$position.'.delay_minutes'" label="Notify after (minutes)" type="number" min="1" max="10080" :value="$step['delay_minutes'] ?? null" description="1–10,080 minutes (up to seven days)." />
                </x-signal.ui.card>
            @endfor
        </div>
    @endif
    @error('escalations')<p class="text-xs text-danger dark:text-danger">{{ $message }}</p>@enderror
    @if($escalationLimit === null || $escalationLimit > 0)<div class="flex flex-wrap items-center gap-4"><x-monitor::ui.button>Save escalation policy</x-monitor::ui.button><span class="text-xs text-muted dark:text-subtle">{{ $escalationLimit === null ? 'Up to 10 steps' : 'Up to '.$escalationLimit.' steps on this plan' }}</span></div>@endif
</x-signal.ui.panel>
@endcan
@can('delete', $alertRule)
<x-signal.ui.panel as="form" method="POST" action="{{ route('monitor.alerts.destroy', $alertRule) }}" data-confirm="Archive this rule and close its active incident? Incident history will be retained." class="shadow-none flex flex-col items-start gap-3 p-5">
    @csrf @method('DELETE') <x-signal.ui.input type="hidden" name="version" value="{{ $alertRule->state_version }}" :restore="false" />
    <p class="text-xs text-muted dark:text-subtle">Archiving stops evaluations and preserves incident history. It does not mark the monitored service healthy.</p><x-monitor::ui.button variant="danger">Archive rule</x-monitor::ui.button>
</x-signal.ui.panel>
@endcan
@endsection
