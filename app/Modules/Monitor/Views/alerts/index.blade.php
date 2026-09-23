@extends('monitor::layouts.app')
@section('title', 'Alert rules')
@section('breadcrumb', 'Alert rules')
@section('content')
<x-monitor::ui.page-header eyebrow="Reliability" title="Alert rules" description="Turn telemetry thresholds into actionable incidents.">
    <x-slot:actions>@if($canCreate)<x-monitor::ui.button :href="route('monitor.alerts.create')">Create alert rule</x-monitor::ui.button>@endif</x-slot:actions>
</x-monitor::ui.page-header>
<p class="ui-alert border-primary/30 bg-primary-soft block p-4 text-xs leading-5 text-primary dark:text-primary">Rules are checked every minute. Incidents appear in the inbox. To receive external notifications, configure email, Slack, Microsoft Teams, PagerDuty, Discord or signed webhook destinations and select them on each rule.</p>
<form method="GET" action="{{ route('monitor.alerts.index') }}" class="flex flex-wrap items-end gap-3">
    <x-monitor::ui.select name="state" label="Rule state" :value="$state" :options="['all' => 'Current rules', 'enabled' => 'Enabled', 'paused' => 'Paused rules', 'archived' => 'Archived']" />
    <x-monitor::ui.button variant="secondary">Filter</x-monitor::ui.button>
</form>
<div class="ui-card overflow-x-auto">
    <x-monitor::ui.table caption="Alert rules and latest evaluations" :framed="false">
        <x-slot:head><tr><th scope="col">Rule / environment</th><th scope="col">Condition</th><th scope="col">Evaluation</th><th scope="col">Last checked (UTC)</th></tr></x-slot:head>
@forelse($rules as $rule)
            <tr><td class="max-w-sm"><a href="{{ route('monitor.alerts.show', $rule) }}" class="break-words font-semibold text-primary hover:underline dark:text-primary">{{ $rule->name }}</a><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $rule->environment->application->name }} / {{ $rule->environment->name }}</p></td><td><p>{{ $rule->metric->label() }} {{ $rule->comparisonLabel() }} {{ $rule->thresholdValue() }}</p><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $rule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::SloBurnRate ? 'SLO: '.($rule->serviceLevelObjective?->name ?? 'Unavailable') : $rule->window_minutes.' minute window · '.($rule->metric === \App\Modules\Monitor\Data\Telemetry\AlertMetric::LogPatternCount ? 'Pattern: '.$rule->match_text : (in_array($rule->metric, [\App\Modules\Monitor\Data\Telemetry\AlertMetric::NumericMetric, \App\Modules\Monitor\Data\Telemetry\AlertMetric::MetricAnomaly], true) ? ($rule->metricSeries?->name.' · '.$rule->metricSeries?->resource_label) : ($rule->service ?? 'All services'))) }}</p></td><td><x-monitor::ui.alert-state :rule="$rule" /></td><td class="whitespace-nowrap">{{ $rule->checked_at?->format('Y-m-d H:i:s') ?? 'Not yet checked' }}</td></tr>
        @empty
            <tr><td colspan="4" class="py-12 text-center text-muted dark:text-subtle">No rules yet. Create one for an environment that sends telemetry.</td></tr>
        @endforelse
    </x-monitor::ui.table>
</div>
{{ $rules->links() }}
@endsection
