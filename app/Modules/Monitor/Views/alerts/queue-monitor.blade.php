@extends('monitor::layouts.app')
@section('title', $monitor->name)
@section('breadcrumb', 'Monitors')
@section('content')
<a href="{{ route('monitor.monitors.index', ['check_type' => 'queue']) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Queue monitors</a>
<x-monitor::ui.page-header :title="$monitor->name" :description="$monitor->environment->application->name.' / '.$monitor->environment->name.' · '.$monitor->queue_name">
    <x-slot:actions>
        <x-monitor::ui.badge :tone="$monitor->healthLabel() === 'Up' ? 'green' : ($monitor->healthLabel() === 'Down' ? 'red' : 'slate')">{{ $monitor->healthLabel() }}</x-monitor::ui.badge>
        @can('update', $monitor)<x-monitor::ui.button :href="route('monitor.monitors.edit', $monitor)" variant="secondary">Edit monitor</x-monitor::ui.button>@endcan
    </x-slot:actions>
</x-monitor::ui.page-header>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach(['pending' => 'Ready jobs', 'failed' => 'Failed / dead-letter jobs', 'oldest_wait_seconds' => 'Oldest ready wait (seconds)'] as $field => $label)
    <x-signal.ui.panel as="section" class="p-5"><h2 class="text-xs text-muted dark:text-subtle">{{ $label }}</h2><p class="mt-2 text-3xl font-bold">{{ $snapshot?->{$field} === null ? '—' : number_format($snapshot->{$field}) }}</p><p class="mt-2 text-xs text-muted dark:text-subtle">{{ $current['fresh_snapshot'] ? 'Latest fresh sample' : 'Missing or stale sample' }}</p></x-signal.ui.panel>
    @endforeach
    <x-signal.ui.panel as="section" class="p-5"><h2 class="text-xs text-muted dark:text-subtle">Live workers · current revision</h2><p class="mt-2 text-3xl font-bold">{{ $current['active_workers'] }}</p><p class="mt-2 text-xs text-muted dark:text-subtle">{{ $current['busy_workers'] }} busy · {{ $current['long_running_workers'] }} overlong</p></x-signal.ui.panel>
</div>
<p class="text-xs leading-5 text-muted dark:text-subtle">Reported gauges, not a throughput rate or availability SLO. Missing data never means zero. The status badge is the last evaluated result; current worker counts use heartbeat expiry at page load. Paused and archived monitors do not evaluate health. Refresh to see new signals.</p>
<div class="grid items-start gap-6 xl:grid-cols-3">
    <x-signal.ui.panel as="section" class="space-y-4 p-6 xl:col-span-2">
        <h2 class="text-lg font-bold">Ready-job backlog</h2>
        @if($chart->isNotEmpty())
        <svg viewBox="0 0 600 160" class="h-40 w-full" role="img" aria-label="Ready-job counts for up to forty queue samples, oldest to newest. Exact values appear in snapshot history.">
            @foreach($chart as $position => $point)
            <rect x="{{ $position * 15 + 1 }}" y="{{ 150 - max(2, $point->pending / $chartMaximum * 140) }}" width="11" height="{{ max(2, $point->pending / $chartMaximum * 140) }}" rx="2" class="{{ $monitor->queue_settings['max_pending'] !== null && $point->pending > $monitor->queue_settings['max_pending'] ? 'fill-danger' : 'fill-primary' }}"><title>{{ $point->observed_at->format('Y-m-d H:i:s') }} UTC · {{ $point->pending }} ready jobs</title></rect>
            @endforeach
        </svg>
        <p class="text-xs text-muted dark:text-subtle">Oldest → newest · chart maximum {{ number_format($chartMaximum) }} jobs · current configuration only. Bars represent samples, not equal spans of elapsed time. Missing samples are not backfilled.</p>
        @else<p class="py-10 text-center text-sm text-muted dark:text-subtle">The chart will appear after the first current queue sample.</p>@endif
        <h3 class="text-sm font-bold">Latest evaluated result</h3>
        <x-monitor::ui.monitor-observation :observation="$monitor->observation" />
        <p class="text-xs text-muted dark:text-subtle">Last evaluated {{ $monitor->checked_at ? $monitor->checked_at->format('Y-m-d H:i:s').' UTC' : 'not yet collected' }}. {{ $snapshot ? 'Latest sample observed '.$snapshot->observed_at->format('Y-m-d H:i:s').' UTC; received '.$snapshot->received_at->format('Y-m-d H:i:s').' UTC.' : 'No current snapshot received.' }}</p>
    </x-signal.ui.panel>
    <x-signal.ui.card as="aside" class="space-y-4 p-6">
        <h2 class="font-bold">Queue conditions</h2>
        <dl class="space-y-3 text-sm">
            @foreach(\App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings::LABELS as $field => $label)<div><dt class="text-xs text-muted dark:text-subtle">{{ $label }}</dt><dd class="mt-1 font-semibold">{{ isset($monitor->queue_settings[$field]) ? number_format($monitor->queue_settings[$field]) : 'Disabled' }}</dd></div>@endforeach
            <div><dt class="text-xs text-muted dark:text-subtle">Next signal deadline (UTC)</dt><dd class="mt-1">{{ $monitor->enabled && !$monitor->trashed() ? ($monitor->next_check_at?->format('Y-m-d H:i:s') ?? 'Awaiting a new signal after expiry') : 'Paused' }}</dd></div>
        </dl>
        @can('delete', $monitor)<form method="POST" action="{{ route('monitor.monitors.destroy', $monitor) }}" class="space-y-3 border-t border-line pt-4 dark:border-line">@csrf @method('DELETE')<x-signal.ui.input type="hidden" name="version" value="{{ $monitor->state_version }}" :restore="false" /><p class="text-xs text-muted dark:text-subtle">Archiving revokes the queue key, stops evaluation and closes incidents without claiming recovery. History is retained.</p><x-monitor::ui.button variant="secondary">Archive monitor</x-monitor::ui.button></form>@endcan
    </x-signal.ui.card>
</div>
@include('monitor::alerts.queue-setup')
<section class="space-y-4">
    <h2 class="text-lg font-bold">Workers</h2>
    <p class="text-xs text-muted dark:text-subtle">Each UUID represents one worker boot. Stopped, missing and previous-configuration workers remain in history; these rows do not prove a process is currently running.</p>
    <x-signal.ui.card as="div" class="overflow-x-auto"><x-monitor::ui.table caption="Worker heartbeat history" :framed="false">
        <x-slot:head><tr><th scope="col">Worker / revision</th><th scope="col">Liveness</th><th scope="col">Last heartbeat (UTC)</th><th scope="col">Latest reported execution</th></tr></x-slot:head>
@forelse($workers as $worker)<tr>
            <td><code class="text-xs">{{ $worker->worker_id }}</code><p class="mt-1 text-xs text-muted dark:text-subtle">Revision {{ $worker->config_revision }} · sequence {{ $worker->last_sequence }}</p></td>
            <td>{{ $worker->config_revision !== $monitor->config_revision ? 'Previous configuration' : ($worker->status === 'stopped' ? 'Stopped' : ($worker->last_seen_at->addSeconds($monitor->queue_settings['worker_timeout_seconds'])->lte($now) ? 'Missing heartbeat' : ucfirst($worker->status))) }}</td>
            <td class="whitespace-nowrap">{{ $worker->last_seen_at->format('Y-m-d H:i:s') }}</td>
            <td><code>{{ $worker->job_id ?? 'No busy job' }}</code>@if($worker->job_started_at)<p class="mt-1 text-muted dark:text-subtle">First busy signal {{ $worker->job_started_at->format('Y-m-d H:i:s') }} UTC</p>@endif</td>
        </tr>@empty<tr><td colspan="4" class="py-10 text-center text-muted dark:text-subtle">No worker signals received.</td></tr>@endforelse
    </x-monitor::ui.table></x-signal.ui.card>
    {{ $workers->links() }}
</section>
<section class="space-y-4">
    <h2 class="text-lg font-bold">Snapshot history</h2>
    <x-signal.ui.card as="div" class="overflow-x-auto"><x-monitor::ui.table caption="Queue snapshot history" :framed="false">
        <x-slot:head><tr><th scope="col">Sample / revision</th><th scope="col">Observed / received (UTC)</th><th scope="col">Ready / delayed / reserved / failed</th><th scope="col">Oldest ready wait</th></tr></x-slot:head>
@forelse($snapshots as $sample)<tr>
            <td><code class="text-xs">{{ $sample->snapshot_id }}</code><p class="mt-1 text-xs text-muted dark:text-subtle">Revision {{ $sample->config_revision }} · {{ $sample->applied ? 'Applied at receipt' : 'Historical only' }}{{ $sample->id === $monitor->queue_snapshot_id ? ' · current sample' : '' }}</p></td>
            <td class="whitespace-nowrap">{{ $sample->observed_at->format('Y-m-d H:i:s.u') }}<p class="mt-1 text-muted dark:text-subtle">{{ $sample->received_at->format('Y-m-d H:i:s.u') }}</p></td>
            <td>{{ $sample->pending }} / {{ $sample->delayed ?? '—' }} / {{ $sample->reserved ?? '—' }} / {{ $sample->failed ?? '—' }}</td>
            <td>{{ $sample->oldest_wait_seconds === null ? 'Unknown' : $sample->oldest_wait_seconds.' sec' }}</td>
        </tr>@empty<tr><td colspan="4" class="py-10 text-center text-muted dark:text-subtle">No queue samples received.</td></tr>@endforelse
    </x-monitor::ui.table></x-signal.ui.card>
    {{ $snapshots->links() }}
</section>
<section class="space-y-4">
    <h2 class="text-lg font-bold">Evaluation history</h2>
    <x-signal.ui.card as="div" class="overflow-x-auto"><x-monitor::ui.table caption="Queue evaluation history" :framed="false">
        <x-slot:head><tr><th scope="col">Evaluated (UTC)</th><th scope="col">Outcome</th><th scope="col">Evidence</th></tr></x-slot:head>
@forelse($checks as $check)<tr>
            <td class="whitespace-nowrap">{{ $check->scheduled_at->format('Y-m-d H:i:s') }} · revision {{ $check->config_revision }}</td>
            <td><strong>{{ ucfirst($check->outcome) }}</strong><p class="mt-1">{{ \App\Modules\Monitor\Data\Telemetry\MonitorObservation::label($check->reason) }}</p></td>
            <td class="min-w-80"><x-monitor::ui.monitor-details :details="$check->details ?? []" /></td>
        </tr>@empty<tr><td colspan="3" class="py-10 text-center text-muted dark:text-subtle">No evaluations yet.</td></tr>@endforelse
    </x-monitor::ui.table></x-signal.ui.card>
    {{ $checks->links() }}
    <p class="text-xs text-muted dark:text-subtle">Applied snapshots record an outcome. Worker signals and deadline checks add an outcome only when the health conditions change. Replays and historical-only samples do not add outcomes.</p>
</section>
<section class="space-y-4"><h2 class="text-lg font-bold">Queue incidents</h2><x-monitor::ui.incident-list :incidents="$incidents" /></section>
@endsection
