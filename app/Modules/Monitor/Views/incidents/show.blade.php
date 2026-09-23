@extends('monitor::layouts.app')
@section('title', 'Incident #'.$incident->id)
@section('breadcrumb', 'Incidents')
@section('content')
@php($source = $incident->source())
<a href="{{ route('monitor.incidents.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Incident inbox</a>
<x-monitor::ui.page-header :title="'#'.$incident->id.' · '.$incident->title" :description="$source->environment->application->name.' / '.$source->environment->name">
    <x-slot:actions><x-monitor::ui.badge :tone="$incident->status === 'open' ? 'red' : ($incident->status === 'acknowledged' ? 'amber' : 'slate')">{{ $incident->statusLabel() }}</x-monitor::ui.badge></x-slot:actions>
</x-monitor::ui.page-header>
<div class="grid items-start gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <section class="ui-panel space-y-4 p-6">
            <h2 class="text-lg font-bold">Latest observation for this incident</h2>@if($incident->monitor_id)<x-monitor::ui.monitor-observation :observation="$incident->latest_observation" />@else<x-monitor::ui.alert-observation :observation="$incident->latest_observation" />@endif
            <p class="text-xs text-muted dark:text-subtle">Opened {{ $incident->opened_at->format('Y-m-d H:i:s') }} UTC · Last observed breach {{ $incident->last_breached_at->format('Y-m-d H:i:s') }} UTC</p>
            @if($incident->resolved_at)<p class="text-sm">{{ $incident->statusLabel() }} at {{ $incident->resolved_at->format('Y-m-d H:i:s') }} UTC.</p>@endif
            @if($incident->acknowledged_at)<p class="text-sm">Acknowledged by {{ $incident->acknowledgedBy?->name ?? 'Former teammate' }} at {{ $incident->acknowledged_at->format('Y-m-d H:i:s') }} UTC.</p>@endif
            <p class="text-sm">Assigned owner: {{ $incident->assignee?->name ?? 'Unassigned' }}.</p>
        </section>
        <section class="ui-panel space-y-4 p-6"><h2 class="text-lg font-bold">Opening evidence</h2>
            @if($incident->monitor_id)
            <p class="text-sm">
                @if(($incident->rule_snapshot['type'] ?? 'http') === 'http')
                    {{ $incident->rule_snapshot['method'] }} · HTTP {{ $incident->rule_snapshot['status_min'] }}–{{ $incident->rule_snapshot['status_max'] }}
                @elseif($incident->rule_snapshot['type'] === 'dns')
                    DNS {{ $incident->rule_snapshot['dns_record_type'] }} · {{ $incident->rule_snapshot['dns_match'] }} · {{ $incident->rule_snapshot['expected_count'] }} expected records
                @elseif($incident->rule_snapshot['type'] === 'tcp')
                    TCP port {{ $incident->rule_snapshot['tcp_port'] }} · connection only
                @elseif($incident->rule_snapshot['type'] === 'queue')
                    Queue / workers · {{ $incident->rule_snapshot['queue_name'] }} · report timeout {{ $incident->rule_snapshot['queue_settings']['report_timeout_seconds'] }} sec · minimum {{ $incident->rule_snapshot['queue_settings']['minimum_workers'] }} live workers
                @elseif($incident->rule_snapshot['type'] === 'heartbeat')
                    Cron / heartbeat · {{ $incident->rule_snapshot['heartbeat_schedule'] === 'cron' ? $incident->rule_snapshot['heartbeat_cron'].' · '.$incident->rule_snapshot['heartbeat_timezone'] : $incident->rule_snapshot['heartbeat_interval_minutes'].' minute interval' }} · {{ $incident->rule_snapshot['heartbeat_grace_minutes'] }} minute grace
                @else
                    TLS certificate · expiry threshold {{ $incident->rule_snapshot['tls_expiry_days'] }} days
                @endif
                · {{ $incident->rule_snapshot['trigger_checks'] }} failures to open · {{ $incident->rule_snapshot['recovery_checks'] }} passes to recover. Configuration captured at opening.
            </p>
            <x-monitor::ui.monitor-observation :observation="$incident->opening_observation" />
            @else
            <p class="text-sm">{{ \App\Modules\Monitor\Data\Telemetry\AlertMetric::from($incident->rule_snapshot['metric'])->label() }} {{ ($incident->rule_snapshot['metric'] ?? null) === 'telemetry_volume' || ($incident->rule_snapshot['comparison'] ?? 'gte') === 'lte' ? '≤' : '≥' }} {{ $incident->rule_snapshot['threshold'] }} · {{ $incident->rule_snapshot['window_minutes'] }} minute window · {{ $incident->rule_snapshot['metric_series_name'] ?? ($incident->rule_snapshot['service'] ?? 'All services') }}{{ isset($incident->rule_snapshot['metric_resource_label']) ? ' · '.$incident->rule_snapshot['metric_resource_label'] : '' }}</p>
            <p class="text-xs text-muted dark:text-subtle">Minimum {{ $incident->rule_snapshot['minimum_samples'] }} samples · {{ $incident->rule_snapshot['trigger_checks'] }} checks to open · {{ $incident->rule_snapshot['recovery_checks'] }} checks to recover. Configuration captured when this incident opened.</p>
            <x-monitor::ui.alert-observation :observation="$incident->opening_observation" />
            @endif
        </section>
        <section class="space-y-4"><h2 class="text-lg font-bold">Activity</h2>
            <ol class="space-y-3">@foreach($activities as $activity)<li class="ui-card p-5"><div class="flex flex-wrap justify-between gap-2"><p class="text-sm font-semibold">{{ $activity->label() }}</p><time class="text-xs text-muted dark:text-subtle">{{ $activity->created_at->format('Y-m-d H:i:s') }} UTC</time></div><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $activity->actor?->name ?? 'System / former teammate' }}</p>@if($activity->action === 'assign')<p class="mt-3 text-xs text-muted dark:text-subtle">Assigned to {{ $assignees->firstWhere('id', $activity->metadata['assignee_id'] ?? null)?->name ?? (($activity->metadata['assignee_id'] ?? null) === null ? 'no one' : 'a former contributor') }}.</p>@endif @if($activity->note !== null)<p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $activity->note }}</p>@endif</li>@endforeach</ol>
            {{ $activities->links() }}
        </section>
    </div>
    <aside class="space-y-5">
        <section class="ui-panel space-y-4 p-5"><h2 class="font-bold">Incident ownership</h2>
            @can('update', $incident)
            <form method="POST" action="{{ route('monitor.incidents.update', $incident) }}" class="space-y-4">
                @csrf @method('PATCH') <input type="hidden" name="action" value="assign"><input type="hidden" name="version" value="{{ $incident->state_version }}">
                <x-monitor::ui.select name="assignee_id" label="Workspace contributor" :value="$incident->assignee_id" :options="$assignees->pluck('name', 'id')->all()" placeholder="Unassigned" />
                <x-monitor::ui.button variant="secondary">Update owner</x-monitor::ui.button>
            </form>
            @else
            <p class="text-sm">{{ $incident->assignee?->name ?? 'Unassigned' }}</p>
            <p class="text-xs leading-5 text-muted dark:text-subtle">Your viewer role is read-only. A workspace contributor can assign this incident.</p>
            @endcan
        </section>
        @if($incident->monitor_id)
        <section class="ui-panel space-y-4 p-5"><h2 class="font-bold">Current monitor state</h2><p class="text-sm">{{ $source->healthLabel() }}</p><p class="text-xs text-muted dark:text-subtle">Pausing retains active incidents. Unknown and missed checks never indicate recovery.</p><a href="{{ route('monitor.monitors.show', $source) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">View monitor and history →</a></section>
        @else
        <section class="ui-panel space-y-4 p-5"><h2 class="font-bold">Current rule state</h2><x-monitor::ui.alert-state :rule="$incident->alertRule" /><p class="text-xs leading-5 text-muted dark:text-subtle">Pausing a rule or its source does not resolve active incidents. Missing telemetry is not evidence of recovery.</p><a href="{{ route('monitor.alerts.show', $incident->alertRule) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">View rule and history →</a></section>
        @endif
        @if($deploymentContextMinutes === 0)
        <section class="ui-alert border-primary/30 bg-primary-soft block space-y-3 p-5"><h2 class="font-bold text-primary dark:text-primary">Deployment context</h2><p class="text-xs leading-5 text-primary dark:text-primary">See recent releases in the affected environment alongside an incident. This change correlation view is available on paid plans.</p><a href="{{ route('monitor.settings.billing') }}" class="text-xs font-bold text-primary underline dark:text-primary">Review plans →</a></section>
        @else
        <section class="ui-panel space-y-4 p-5"><div><h2 class="font-bold">Recent deployments</h2><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Deployments to this environment in the {{ $deploymentContextMinutes }} minutes before the incident opened. This is a lead for investigation, not proof of causation.</p></div>
            <div class="divide-y divide-line dark:divide-line">@forelse($recentDeployments as $deployment)<a href="{{ route('monitor.deployments.show', [$deployment->environment->application_id, $deployment->environment_id, $deployment->id]) }}" class="block py-3 first:pt-0 last:pb-0 hover:text-primary dark:hover:text-primary"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-sm font-semibold">{{ $deployment->release->version }}</p><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $deployment->release->serviceLabel() }} · {{ $deployment->actor?->name ?? 'Automated deployment' }}</p></div><time class="shrink-0 text-right text-[11px] text-muted dark:text-subtle">{{ $deployment->deployed_at->utc()->format('Y-m-d H:i') }} UTC</time></div></a>@empty<p class="text-sm text-muted dark:text-subtle">No deployment was recorded in this context window.</p>@endforelse</div>
        </section>
        @endif
        @can('update', $incident)
        <form method="POST" action="{{ route('monitor.incidents.update', $incident) }}" class="ui-panel space-y-4 p-5">
            @csrf @method('PATCH') <input type="hidden" name="version" value="{{ $incident->state_version }}">
            <h2 class="font-bold">Respond</h2>
            <x-monitor::ui.select name="action" label="Action" :value="$incident->status === 'open' ? 'acknowledge' : 'note'" :options="$incident->status === 'open' ? ['acknowledge' => 'Acknowledge incident', 'note' => 'Add note'] : ['note' => 'Add note']" />
            <x-monitor::ui.input name="note" label="Note (required for Add note; no secrets)" maxlength="1000" />
            <p class="text-xs leading-5 text-muted dark:text-subtle">Acknowledgement records who is investigating. Only confirmed successful evaluations mark an incident recovered.</p><x-monitor::ui.button>Save response</x-monitor::ui.button>
        </form>
        @endcan
    </aside>
</div>
@endsection
