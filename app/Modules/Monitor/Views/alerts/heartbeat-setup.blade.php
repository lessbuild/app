<section class="ui-panel space-y-5 p-6">
    <div><h2 class="text-lg font-bold">Connect your job</h2><p class="mt-2 text-sm text-muted dark:text-subtle">{{ $runningCount }} running · last signal {{ $monitor->heartbeat_received_at ? $monitor->heartbeat_received_at->format('Y-m-d H:i:s').' UTC' : 'not received' }} · last current-run success {{ $monitor->heartbeat_succeeded_at ? $monitor->heartbeat_succeeded_at->format('Y-m-d H:i:s').' UTC' : 'not received' }}</p></div>
    @if(!request()->isSecure())<p class="ui-alert ui-alert-warning block p-4 text-sm text-warning dark:text-warning">This preview uses HTTP. Use disposable test keys only. Configure HTTPS before sending production credentials, then rotate keys created during preview.</p>@endif
    @if($heartbeatSecret !== null)
    <div class="ui-alert border-primary/30 bg-primary-soft block space-y-2 p-4">
        <p class="text-sm font-bold">Copy your heartbeat key now</p><code class="block break-all text-sm">{{ $heartbeatSecret }}</code>
        <p class="text-xs">This is its only display. Store it as BEACON_HEARTBEAT_KEY in your job's secret manager; do not commit it or put it in a URL.</p>
    </div>
    @endif
    @can('update', $monitor)
    <div class="flex flex-wrap gap-3">
        <form method="POST" action="{{ route('monitor.monitors.heartbeat-key.store', $monitor) }}">@csrf<input type="hidden" name="version" value="{{ $monitor->state_version }}"><x-monitor::ui.button variant="secondary">{{ $monitor->heartbeat_token_hash ? 'Rotate heartbeat key' : 'Generate heartbeat key' }}</x-monitor::ui.button></form>
        @if($monitor->heartbeat_token_hash)<form method="POST" action="{{ route('monitor.monitors.heartbeat-key.destroy', $monitor) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $monitor->state_version }}"><x-monitor::ui.button variant="secondary">Revoke key and pause</x-monitor::ui.button></form>@endif
    </div>
    <p class="text-xs text-muted dark:text-subtle">Rotation immediately invalidates the old key. It does not reset deadlines or recover incidents.</p>
    @endcan
    <p class="text-sm">Send JSON with a fresh UUID for every job run. Reuse that UUID for start, success or failure signals and retries. Signals use server receipt time; keep retries prompt.</p>
    <x-monitor::ui.code-block id="heartbeat-example-1" language="HTTP / JSON">POST {{ route('monitor.api.heartbeats.store', ['heartbeat' => $monitor->id]) }}
Authorization: Bearer &lt;your heartbeat key&gt;
Content-Type: application/json

{"run_id":"&lt;fresh UUID for this run&gt;","signal":"start"}</x-monitor::ui.code-block>
    <p class="text-xs leading-5 text-muted dark:text-subtle">Use signal “success” after the job finishes, or “failure” when it fails. Completion-only heartbeats are supported without “start”, but cannot measure run duration. Sending “start” lets Monitor detect unfinished runs and associate delayed completions with the correct run.</p>
    <p class="text-xs leading-5 text-muted dark:text-subtle">The newest first-seen run controls health; older overlapping runs remain in history without overriding it. Each run can have only one terminal signal. Up to 100 concurrent runs and 60 signals per minute per monitor. Raw logs, credentials and client timestamps are not accepted in the body.</p>
    <p class="text-xs leading-5 text-muted dark:text-subtle">Pausing the environment also pauses its heartbeat monitors. Resume each monitor explicitly for a fresh deadline window. Archiving the application or environment revokes heartbeat keys.</p>
</section>
@if($runs)
<section class="space-y-4">
    <h2 class="text-lg font-bold">Job runs</h2>
    <div class="ui-card overflow-x-auto">
        <x-monitor::ui.table caption="Recent heartbeat runs" :framed="false">
            <x-slot:head><tr><th scope="col">Run ID / revision</th><th scope="col">Status</th><th scope="col">Started (UTC)</th><th scope="col">Finished / deadline (UTC)</th></tr></x-slot:head>
@forelse($runs as $run)<tr>
            <td><code class="text-xs">{{ $run->run_id }}</code><p class="mt-1 text-xs text-muted dark:text-subtle">Revision {{ $run->config_revision }}{{ $monitor->heartbeat_sequence === $run->id ? ' · current run' : '' }}</p></td>
            <td>{{ ucfirst(str_replace('_', ' ', $run->status)) }}</td>
            <td class="whitespace-nowrap">{{ $run->started_at?->format('Y-m-d H:i:s') ?? 'Completion-only signal' }}</td>
            <td class="whitespace-nowrap">{{ $run->finished_at?->format('Y-m-d H:i:s') ?? 'Not finished' }}<p class="mt-1 text-muted dark:text-subtle">Deadline {{ $run->deadline_at?->format('Y-m-d H:i:s') ?? 'Schedule + grace' }}</p></td>
        </tr>@empty<tr><td colspan="4" class="py-10 text-center text-muted dark:text-subtle">No job signals received yet.</td></tr>@endforelse
        </x-monitor::ui.table>
    </div>
    {{ $runs->links() }}
</section>
@endif
