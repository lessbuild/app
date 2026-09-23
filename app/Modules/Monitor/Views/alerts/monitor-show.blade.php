@extends('monitor::layouts.app')
@section('title', $monitor->name)
@section('breadcrumb', 'Monitors')
@section('content')
<a href="{{ route('monitor.monitors.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Monitors</a>
<x-monitor::ui.page-header eyebrow="Monitor details" :title="$monitor->name" :description="$monitor->environment->application->name.' / '.$monitor->environment->name.' · '.$monitor->targetLabel()">
    <x-slot:actions>
        <x-monitor::ui.badge :tone="$monitor->healthLabel() === 'Up' ? 'green' : ($monitor->healthLabel() === 'Down' ? 'red' : 'slate')">{{ $monitor->healthLabel() }}</x-monitor::ui.badge>
        @can('update', $monitor)<x-monitor::ui.button :href="route('monitor.monitors.edit', $monitor)" variant="secondary">Edit monitor</x-monitor::ui.button>@endcan
    </x-slot:actions>
</x-monitor::ui.page-header>
@if($monitor->type === 'heartbeat') @include('monitor::alerts.heartbeat-setup') @endif
<div class="grid gap-4 sm:grid-cols-3">
    <x-monitor::ui.stat-card :label="$monitor->type === 'heartbeat' ? 'Outcome records · last 24 hours' : 'Check success rate · last 24 hours'" :value="$successRate === null ? '—' : number_format($successRate, 2).'%'" :caption="(int) $summary->passed.' passed · '.(int) $summary->failed.' failed'" icon="shield" />
    <x-monitor::ui.stat-card :label="$monitor->type === 'heartbeat' ? 'Mean server-observed run duration' : 'Mean measured response time'" :value="$summary->mean_ms === null ? '—' : number_format($summary->mean_ms, 1).' ms'" caption="Current configuration only" icon="clock" />
    <x-monitor::ui.stat-card label="Coverage · last 24 hours" :value="(int) $summary->total.' checks'" :caption="(int) $summary->unknown_count.' unknown · '.(int) $summary->skipped.' skipped intervals'" />
</div>
@if($monitor->type === 'heartbeat')
<p class="text-xs leading-5 text-muted dark:text-subtle">Counts include completion signals and missed deadlines, including older runs; they are not a per-job success rate or availability SLO. Starts and retries do not create outcome records. Duration is measured between server receipt of start and completion, including network delay. Missed schedule slots are not backfilled.</p>
@else
<p class="text-xs leading-5 text-muted dark:text-subtle">Success rate counts observed passes and failures, not time-based availability or an SLO. Unknown, cancelled and pending checks are excluded. Gaps are not backfilled. Checks run from one configured location; regional redundancy is not yet available.</p>
@endif
<div class="grid items-start gap-6 xl:grid-cols-3">
    <section class="ui-panel space-y-4 p-6 xl:col-span-2">
        <h2 class="text-lg font-bold">{{ $monitor->type === 'heartbeat' ? 'Recent run durations' : 'Recent response times' }}</h2>
        @if($chart->whereNotNull('duration_ms')->isNotEmpty())
        <svg viewBox="0 0 600 160" class="h-40 w-full" role="img" aria-label="{{ $monitor->type === 'heartbeat' ? 'Run durations' : 'Response times' }} for up to forty recent checks, oldest to newest. Details in the check history table.">
            @foreach($chart as $position => $point)
                @if($point->duration_ms !== null)
                <rect x="{{ $position * 15 + 1 }}" y="{{ 150 - max(2, $point->duration_ms / $chartMaximum * 140) }}" width="11" height="{{ max(2, $point->duration_ms / $chartMaximum * 140) }}" rx="2" class="{{ $point->outcome === 'down' ? 'fill-danger' : 'fill-primary' }}"><title>{{ $point->scheduled_at->format('H:i:s') }} UTC · {{ number_format($point->duration_ms, 1) }} ms · {{ $point->outcome }}</title></rect>
                @else
                <rect x="{{ $position * 15 + 1 }}" y="148" width="11" height="2" class="fill-subtle"><title>No duration measured</title></rect>
                @endif
            @endforeach
        </svg>
        <p class="text-xs text-muted dark:text-subtle">Oldest → newest · chart maximum {{ number_format($chartMaximum, 1) }} ms · each bar is a check, not an equal span of elapsed time.</p>
        @else<p class="py-10 text-center text-sm text-muted dark:text-subtle">The chart will appear after a measured check.</p>@endif
        <x-monitor::ui.monitor-observation :observation="$monitor->observation" />
        <p class="text-xs text-muted dark:text-subtle">Last observation {{ $monitor->checked_at ? $monitor->checked_at->format('Y-m-d H:i:s').' UTC' : 'not yet collected' }}. Latest-result status can become unknown when observations are stale.</p>
    </section>
    <aside class="ui-card space-y-4 p-6">
        <h2 class="font-bold">Check conditions</h2>
        <dl class="space-y-3 text-sm">
            <div><dt class="text-muted dark:text-subtle">Type</dt><dd>{{ $monitor->typeLabel() }}</dd></div>
            @if($monitor->type === 'http')
            <div><dt class="text-muted dark:text-subtle">Request</dt><dd>{{ $monitor->method }} · accepts HTTP {{ $monitor->status_min }}–{{ $monitor->status_max }}</dd></div>
            <div><dt class="text-muted dark:text-subtle">Optional assertions</dt><dd>{{ $monitor->body_contains !== null ? 'Response text required' : 'No response-text assertion' }} · {{ $monitor->max_duration_ms !== null ? 'Maximum '.$monitor->max_duration_ms.' ms' : 'No duration threshold' }}</dd></div>
            @elseif($monitor->type === 'dns')
            <div><dt class="text-muted dark:text-subtle">DNS assertion</dt><dd>{{ $monitor->dns_record_type }} · {{ $monitor->dns_match === 'exact' ? 'Exact set' : 'Contains expected set' }} · {{ count($monitor->dns_expected ?? []) }} expected records</dd></div>
            @elseif($monitor->type === 'heartbeat')
            <div><dt class="text-muted dark:text-subtle">Expected heartbeat</dt><dd>{{ $monitor->targetLabel() }} · {{ $monitor->heartbeat_grace_minutes }} min grace</dd></div>
            @elseif($monitor->type === 'tls')
            <div><dt class="text-muted dark:text-subtle">Certificate assertion</dt><dd>Verified chain and hostname · expiry more than {{ $monitor->tls_expiry_days }} days away · port {{ $monitor->tls_port }}</dd></div>
            @elseif($monitor->type === 'tcp')
            <div><dt class="text-muted dark:text-subtle">TCP assertion</dt><dd>Raw connection established · port {{ $monitor->tcp_port }} · no application payload</dd></div>
            @endif
            @if($monitor->type !== 'heartbeat')<div><dt class="text-muted dark:text-subtle">Schedule</dt><dd>Every {{ $monitor->interval_minutes }} min · {{ $monitor->timeout_seconds }} sec timeout</dd></div>@endif
            <div><dt class="text-muted dark:text-subtle">Incidents</dt><dd>{{ $monitor->trigger_checks }} failures to open · {{ $monitor->recovery_checks }} passes to recover</dd></div>
            <div><dt class="text-muted dark:text-subtle">{{ $monitor->type === 'heartbeat' ? 'Next heartbeat deadline' : 'Next scheduled check' }}</dt><dd>{{ $monitor->enabled && !$monitor->trashed() && $monitor->environment->status === 'active' ? ($monitor->next_check_at ? $monitor->next_check_at->format('Y-m-d H:i:s').' UTC' : 'Awaiting a new run after the missed deadline') : 'Paused' }}</dd></div>
        </dl>
        @if($monitor->type === 'dns')<p class="text-xs text-muted dark:text-subtle">Results are from the system resolver and its cache, not global propagation or DNSSEC verification. Record values below are private to your workspace and are not sent in notifications.</p>@elseif($monitor->type === 'tls')<p class="text-xs text-muted dark:text-subtle">Direct TLS 1.2+ handshake; no HTTP request or STARTTLS. Expiry is for the leaf certificate only. Failed verification may prevent reading expiry metadata. Revocation is not checked.</p>@elseif($monitor->type === 'tcp')<p class="text-xs text-muted dark:text-subtle">A successful result means this checker completed a TCP handshake to the public resolved address. It does not prove that a protocol is healthy or that the service accepted an application request.</p>@endif
        @can('delete', $monitor)<form method="POST" action="{{ route('monitor.monitors.destroy', $monitor) }}" class="space-y-3 border-t border-line pt-4 dark:border-line">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $monitor->state_version }}"><p class="text-xs text-muted dark:text-subtle">Archiving stops checks and closes active incidents without claiming recovery. History remains available.</p><x-monitor::ui.button variant="secondary">Archive monitor</x-monitor::ui.button></form>@endcan
    </aside>
</div>
<section class="space-y-4">
    <h2 class="text-lg font-bold">Check history</h2>
    <div class="ui-card overflow-x-auto"><x-monitor::ui.table caption="Monitor check history" :framed="false">
        <x-slot:head><tr><th scope="col">Scheduled (UTC)</th><th scope="col">Outcome / reason</th><th scope="col">Response</th><th scope="col">Location / revision</th></tr></x-slot:head>
@forelse($checks as $check)<tr>
            <td class="whitespace-nowrap">{{ $check->scheduled_at->format('Y-m-d H:i:s') }}</td>
            <td><span class="font-semibold">{{ ucfirst($check->outcome ?? $check->status) }}</span><p class="mt-1 text-xs text-muted dark:text-subtle">{{ \App\Modules\Monitor\Data\Telemetry\MonitorObservation::label($check->reason) }}</p>@if($check->skipped_intervals)<p class="mt-1 text-xs">{{ $check->skipped_intervals }} intervals skipped before this check</p>@endif</td>
            <td class="min-w-64 space-y-2">@if($monitor->type === 'http')HTTP {{ $check->http_status ?? '—' }} · @endif{{ $check->duration_ms !== null ? number_format($check->duration_ms, 1).' ms' : '—' }}@if($monitor->type !== 'heartbeat')<p class="text-muted dark:text-subtle">DNS {{ $check->dns_ms !== null ? number_format($check->dns_ms, 1).' ms' : '—' }}@if($monitor->type !== 'dns') · Connect {{ $check->connect_ms !== null ? number_format($check->connect_ms, 1).' ms' : '—' }}@endif @if($monitor->type === 'http') · TTFB {{ $check->ttfb_ms !== null ? number_format($check->ttfb_ms, 1).' ms' : '—' }}@endif</p>@endif
                <x-monitor::ui.monitor-details :details="$check->details ?? []" />
                @if($check->evidence)
                <x-monitor::ui.accordion title="DNS record evidence · workspace only"><dl class="mt-3 space-y-3">
                    @foreach(['expected' => 'Expected', 'observed' => 'Observed', 'missing' => 'Missing', 'unexpected' => 'Additional'] as $key => $label)
                    <div><dt class="font-semibold">{{ $label }}</dt><dd class="mt-1 break-all font-mono">@forelse($check->evidence[$key] ?? [] as $value)<p class="whitespace-pre-wrap">{{ $value === '' ? '(empty TXT record)' : $value }}</p>@empty<span class="text-muted dark:text-subtle">None</span>@endforelse</dd></div>
                    @endforeach
                </dl></x-monitor::ui.accordion>
                @endif
            </td>
            <td>{{ $check->location }} · {{ $check->config_revision }}</td>
        </tr>@empty<tr><td colspan="4" class="py-10 text-center text-muted dark:text-subtle">No checks recorded yet.</td></tr>@endforelse
    </x-monitor::ui.table></div>
    {{ $checks->links() }}
</section>
<section class="space-y-4"><h2 class="text-lg font-bold">Monitor incidents</h2><x-monitor::ui.incident-list :incidents="$incidents" /></section>
@endsection
