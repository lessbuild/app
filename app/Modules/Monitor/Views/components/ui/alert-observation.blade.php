@props(['observation' => null])
@if($observation)
    <dl class="grid gap-4 text-sm sm:grid-cols-3">
        <div><dt class="text-xs text-muted dark:text-subtle">Observed value</dt><dd class="mt-1 font-semibold">{{ $observation['value'] === null ? '—' : number_format($observation['value'], 3) }}</dd></div>
        <div><dt class="text-xs text-muted dark:text-subtle">Eligible samples</dt><dd class="mt-1 font-semibold">{{ number_format($observation['samples']) }}</dd></div>
        <div><dt class="text-xs text-muted dark:text-subtle">Result</dt><dd class="mt-1 font-semibold">{{ match($observation['state']) { 'healthy' => 'Below threshold', 'breaching' => 'Threshold breached', 'no_data' => 'Insufficient samples', 'maintenance' => 'Maintenance window active', default => 'Warming up' } }}</dd></div>
    </dl>
    <p class="mt-4 break-words text-xs text-muted dark:text-subtle">Source-time window (UTC): {{ $observation['from'] }} inclusive → {{ $observation['until'] }} exclusive.</p>
@else
    <p class="text-sm text-muted dark:text-subtle">No evaluation yet. The rule waits for a complete window plus a one-minute ingestion delay.</p>
@endif
