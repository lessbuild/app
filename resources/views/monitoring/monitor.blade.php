@php($project = $overview->project)
@php($health = $monitor->healthLabel())
@php($observation = \App\Data\Monitoring\MonitorObservation::class)
@php($signals = in_array($monitor->type, ['heartbeat', 'queue'], true))
@php($hasKey = $monitor->type === 'heartbeat' ? $monitor->heartbeat_token_hash !== null : $monitor->queue_token_hash !== null)

<x-signal.layouts.project :overview="$overview" :title="$monitor->name" :description="$monitor->typeLabel().' · '.$monitor->environment->name.' · '.$monitor->targetLabel()">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3 text-sm text-muted">
            <x-signal.ui.badge :tone="match ($health) { 'Up' => 'success', 'Down' => 'danger', default => 'neutral' }">{{ __($health) }}</x-signal.ui.badge>
            @if ($monitor->observation)
                <span>{{ $observation::label(is_string($monitor->observation['reason'] ?? null) ? $monitor->observation['reason'] : null) }}</span>
            @endif
            @if ($monitor->checked_at)
                <span>· {{ __('Checked :time', ['time' => $monitor->checked_at->diffForHumans()]) }}</span>
            @endif
        </div>
        @if ($canManage)
            <div class="flex flex-wrap gap-2">
                <x-signal.ui.button :href="route('monitoring.monitors.edit', [$project, $monitor->id])" variant="secondary" size="sm">{{ __('Edit') }}</x-signal.ui.button>
                <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="archive-monitor">{{ __('Archive') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="archive-monitor" :route="route('monitoring.monitors.archive', [$project, $monitor->id])" :title="__('Archive :monitor?', ['monitor' => $monitor->name])" :description="__('It stops checking and any open incident closes as “monitor archived”. Its history is kept.')" :warning="$signals ? __('Its key stops working too.') : null" :submit-label="__('Archive monitor')">
                    <input type="hidden" name="version" value="{{ $monitor->state_version }}">
                </x-signal.overlays.delete-confirmation>
            </div>
        @endif
    </div>

    @if (is_array($monitor->observation['details'] ?? null))
        @foreach (\App\Support\Monitoring\ObservationText::details($monitor->observation['details']) as $line)
            <p class="text-xs text-muted">{{ $line }}</p>
        @endforeach
    @endif

    @if ($monitor->trashed())
        <x-signal.ui.alert tone="info">{{ __('This monitor is archived. It no longer runs; its history stays here.') }}</x-signal.ui.alert>
    @endif

    @if ($signals && ! $monitor->trashed())
        @php($endpoint = $monitor->type === 'heartbeat' ? route('api.heartbeats.store', ['heartbeat' => $monitor->id]) : route('api.queues.snapshots.store', ['queue' => $monitor->id]))
        <x-signal.ui.settings-section :title="$monitor->type === 'heartbeat' ? __('Connect your job') : __('Connect your queue')"
            :description="$monitor->type === 'heartbeat' ? __('Send a start, success or failure signal for each run, with a fresh UUID per run.') : __('A collector posts queue counts; each worker posts its own heartbeat.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @if ($issuedKey)
                    <x-signal.ui.alert tone="success" role="status">
                        <p class="font-bold">{{ __('Copy this key now. It won’t be shown again.') }}</p>
                        <x-signal.ui.code-block :code="$issuedKey" class="mt-2 break-all whitespace-pre-wrap" />
                    </x-signal.ui.alert>
                @endif
                <p class="text-sm text-muted">{{ $hasKey ? __('A key is set.') : __('No key yet: signals are refused until you create one.') }}</p>
                @if ($canManage)
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('monitoring.monitors.key.rotate', [$project, $monitor->id]) }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $monitor->state_version }}">
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ $hasKey ? __('Replace key') : __('Create key') }}</x-signal.ui.button>
                        </form>
                        @if ($hasKey)
                            <form method="POST" action="{{ route('monitoring.monitors.key.revoke', [$project, $monitor->id]) }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="version" value="{{ $monitor->state_version }}">
                                <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Revoke key and pause') }}</x-signal.ui.button>
                            </form>
                        @endif
                    </div>
                @endif
                @if ($monitor->type === 'heartbeat')
                    @php($example = 'POST '.$endpoint.PHP_EOL.'Authorization: Bearer <heartbeat key>'.PHP_EOL.'Content-Type: application/json'.PHP_EOL.PHP_EOL.'{"run_id":"<fresh UUID for this run>","signal":"start"}')
                    <x-signal.ui.code-block class="whitespace-pre-wrap break-all" :code="$example" />
                    <p class="text-xs text-muted">{{ __('Then send “success” or “failure” with the same run_id. Up to 100 unfinished runs and 60 signals a minute per monitor.') }}</p>
                @else
                    @php($example = 'POST '.$endpoint.PHP_EOL.'{"snapshot_id":"<UUID>","observed_at":"2026-01-01T00:00:00Z","pending":12,"failed":0}'.PHP_EOL.PHP_EOL.'POST '.route('api.queues.workers.store', ['queue' => $monitor->id]).PHP_EOL.'{"worker_id":"<UUID>","sequence":1,"status":"idle"}')
                    <x-signal.ui.code-block class="whitespace-pre-wrap break-all" :code="$example" />
                    <p class="text-xs text-muted">{{ __('Both use the queue key as a bearer token. Workers send status idle, busy (with job_id) or stopped, and a higher sequence each time.') }}</p>
                @endif
            </div>
        </x-signal.ui.settings-section>
    @endif

    @if ($monitor->type === 'queue' && ($history->snapshot || $history->workers !== []))
        <div class="grid gap-4 sm:grid-cols-3">
            <x-signal.ui.stat :label="__('Ready jobs')" :value="$history->snapshot?->pending ?? '—'" />
            <x-signal.ui.stat :label="__('Failed jobs')" :value="$history->snapshot?->failed ?? '—'" />
            <x-signal.ui.stat :label="__('Workers seen today')" :value="count($history->workers)" />
        </div>
    @endif

    @if ($history->incidents !== [])
        <x-signal.ui.card class="overflow-hidden">
            <h2 class="border-b border-line px-5 py-3 text-sm font-bold text-ink">{{ __('Incidents') }}</h2>
            <ul class="divide-y divide-line">
                @foreach ($history->incidents as $incident)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <a href="{{ route('monitoring.incidents.show', [$project, $incident->id]) }}" class="font-bold text-ink hover:underline">{{ $incident->title }}</a>
                        <span class="text-xs text-muted">{{ $incident->opened_at->diffForHumans() }} · {{ __(ucfirst($incident->status)) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($monitor->type === 'heartbeat')
        <x-signal.ui.table :caption="__('Recent runs')">
            <x-slot:head><tr><th scope="col">{{ __('Run') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Started (UTC)') }}</th><th scope="col">{{ __('Finished or deadline (UTC)') }}</th></tr></x-slot:head>
            @forelse ($history->runs as $run)
                <tr>
                    <td><code class="text-xs">{{ $run->run_id }}</code></td>
                    <td>{{ __(ucfirst(str_replace('_', ' ', $run->status))) }}</td>
                    <td class="whitespace-nowrap">{{ $run->started_at?->format('Y-m-d H:i:s') ?? __('Completion only') }}</td>
                    <td class="whitespace-nowrap">{{ ($run->finished_at ?? $run->deadline_at)?->format('Y-m-d H:i:s') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-muted">{{ __('No signals yet.') }}</td></tr>
            @endforelse
        </x-signal.ui.table>
    @endif

    <x-signal.ui.table :caption="__('Recent checks')">
        <x-slot:head><tr><th scope="col">{{ __('When (UTC)') }}</th><th scope="col">{{ __('Result') }}</th><th scope="col">{{ __('Details') }}</th><th scope="col">{{ __('Duration') }}</th></tr></x-slot:head>
        @forelse ($history->checks as $check)
            <tr>
                <td class="whitespace-nowrap">{{ $check->scheduled_at->format('Y-m-d H:i:s') }}</td>
                <td>
                    @if ($check->status !== 'completed')
                        <x-signal.ui.badge>{{ __(ucfirst($check->status)) }}</x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge :tone="match ($check->outcome) { 'up' => 'success', 'down' => 'danger', default => 'neutral' }">{{ __(ucfirst((string) $check->outcome)) }}</x-signal.ui.badge>
                    @endif
                </td>
                <td class="text-muted">{{ $observation::label($check->reason) }}@if ($check->http_status) · HTTP {{ $check->http_status }}@endif</td>
                <td class="whitespace-nowrap">{{ $check->duration_ms !== null ? number_format($check->duration_ms).' ms' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-8 text-center text-muted">{{ __('No checks yet. The first one runs within a minute.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
</x-signal.layouts.project>
