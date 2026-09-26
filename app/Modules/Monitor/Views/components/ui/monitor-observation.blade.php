@props(['observation'])
@if($observation)
<div class="space-y-2 text-sm">
    <p class="font-semibold">{{ ucfirst($observation['outcome'] ?? 'unknown') }} · {{ \App\Modules\Monitor\Data\Telemetry\MonitorObservation::label($observation['reason'] ?? null) }}</p>
    <p class="text-muted dark:text-subtle">@if(($observation['details']['type'] ?? null) !== 'queue')@if(isset($observation['http_status']))HTTP {{ $observation['http_status'] }} · @endif{{ isset($observation['duration_ms']) ? number_format($observation['duration_ms'], 1).' ms' : 'No duration measured' }} · @endif{{ $observation['location'] ?? 'Unknown checker' }}</p>
    <x-monitor::ui.monitor-details :details="$observation['details'] ?? []" />
</div>
@else
<p class="text-sm text-muted dark:text-subtle">No observation yet.</p>
@endif
