@props(['details' => []])
@if(($details['type'] ?? null) === 'queue')
<div class="space-y-1 text-xs text-muted dark:text-subtle">
    <p>{{ $details['active_workers'] }} live workers · {{ $details['busy_workers'] }} busy · {{ $details['long_running_workers'] }} overlong jobs</p>
    <p>Ready {{ $details['metrics']['pending'] ?? 'unknown' }} · delayed {{ $details['metrics']['delayed'] ?? 'unknown' }} · reserved {{ $details['metrics']['reserved'] ?? 'unknown' }} · failed {{ $details['metrics']['failed'] ?? 'unknown' }}</p>
    <p>Oldest ready wait {{ isset($details['metrics']['oldest_wait_seconds']) ? $details['metrics']['oldest_wait_seconds'].' sec' : 'unknown' }} · sample {{ $details['observed_at'] ?? 'not received' }}{{ $details['fresh_snapshot'] ? '' : ' (missing or stale)' }}</p>
    @foreach($details['breaches'] as $breach)<p>{{ \App\Modules\Monitor\Data\Telemetry\MonitorObservation::label($breach) }}</p>@endforeach
    @if($details['missing_metrics'])<p>Required metrics missing: {{ implode(', ', $details['missing_metrics']) }}. Recovery cannot be established.</p>@endif
</div>
@elseif(($details['type'] ?? null) === 'heartbeat')
<div class="space-y-1 text-xs text-muted dark:text-subtle">
    <p>Deadline {{ $details['deadline_at'] ?? 'not recorded' }} · observed {{ $details['received_at'] }}</p>
    @if($details['run_id'] ?? null)<p class="break-all font-mono">Run {{ $details['run_id'] }}</p>@endif
    @if(!($details['affects_health'] ?? true))<p>Historical run only. A newer run controls monitor health.</p>@endif
</div>
@elseif(($details['type'] ?? null) === 'dns')
<p class="text-xs text-muted dark:text-subtle">{{ $details['record_type'] }} · {{ $details['match'] === 'exact' ? 'Exact set' : 'Contains expected set' }} · {{ $details['observed_count'] }} observed / {{ $details['expected_count'] }} expected · {{ $details['missing_count'] }} missing · {{ $details['unexpected_count'] }} additional</p>
@elseif(($details['type'] ?? null) === 'tcp')
<p class="text-xs text-muted dark:text-subtle">TCP port {{ $details['port'] }} · connection only</p>
@elseif(($details['type'] ?? null) === 'tls')
<div class="space-y-1 text-xs text-muted dark:text-subtle">
    <p>Certificate expires {{ $details['valid_until'] }} · {{ $details['days_remaining'] }} complete days remaining · warning threshold {{ $details['expiry_days'] }} days</p>
    <p>Valid from {{ $details['valid_from'] }}</p>
    <p class="break-all">SHA-256 {{ $details['fingerprint_sha256'] }}</p>
</div>
@endif
