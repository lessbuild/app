<section class="ui-panel space-y-5 p-6">
    <div><h2 class="text-lg font-bold">Connect a queue collector and workers</h2><p class="mt-2 text-sm text-muted dark:text-subtle">Language-neutral JSON endpoints for one logical queue. Integrate with your existing collector or worker instrumentation; no automatic broker discovery or job control is performed.</p></div>
    @if(!request()->isSecure())<p class="ui-alert ui-alert-warning block p-4 text-sm text-warning dark:text-warning">This preview uses HTTP. Use disposable test keys only. Configure HTTPS before sending production credentials, then rotate preview-created keys.</p>@endif
    @if($queueSecret !== null)
    <div class="ui-alert border-primary/30 bg-primary-soft block space-y-2 p-4">
        <p class="text-sm font-bold">Copy your queue key now</p><code class="block break-all text-sm">{{ $queueSecret }}</code>
        <p class="text-xs">This is its only display. Store it in a secret manager as BEACON_QUEUE_KEY. Never put it in a URL, job payload or repository.</p>
    </div>
    @endif
    @can('update', $monitor)
    <div class="flex flex-wrap gap-3">
        <form method="POST" action="{{ route('monitor.monitors.queue-key.store', $monitor) }}">@csrf<input type="hidden" name="version" value="{{ $monitor->state_version }}"><x-monitor::ui.button variant="secondary">{{ $monitor->queue_token_hash ? 'Rotate queue key' : 'Generate queue key' }}</x-monitor::ui.button></form>
        @if($monitor->queue_token_hash)<form method="POST" action="{{ route('monitor.monitors.queue-key.destroy', $monitor) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $monitor->state_version }}"><x-monitor::ui.button variant="secondary">Revoke key and pause</x-monitor::ui.button></form>@endif
    </div>
    <p class="text-xs text-muted dark:text-subtle">Rotation immediately invalidates the old key without resetting deadlines or worker state. Revocation pauses the monitor and retains active incidents.</p>
    @endcan
    <x-monitor::ui.accordion title="1. Send queue snapshots" open>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Sample your broker and send a fresh UUID and UTC observed_at timestamp. Reuse both, with the same values, when retrying that sample. Use one collector per monitor. Freshness starts at sample time, bounded by receipt time; synchronize collector clocks. Older samples remain in history without replacing newer state.</p>
        <x-monitor::ui.code-block id="queue-example-1" language="HTTP / JSON">POST {{ route('monitor.api.queues.snapshots.store', ['queue' => $monitor->id]) }}
Authorization: Bearer &lt;your queue key&gt;
Content-Type: application/json

{
  "snapshot_id": "&lt;fresh sample UUID&gt;",
  "observed_at": "&lt;UTC timestamp, e.g. 2026-09-21T12:00:00.000Z&gt;",
  "pending": 12,
  "delayed": 3,
  "reserved": 2,
  "failed": 0,
  "oldest_wait_seconds": 45
}</x-monitor::ui.code-block>
        <p class="text-xs leading-5 text-muted dark:text-subtle">pending is ready-to-run jobs, excluding delayed and reserved jobs. failed is the current failed / dead-letter backlog, not a lifetime counter or failure rate. oldest_wait_seconds is the age of the oldest ready job (0 for an empty ready queue). Send integers, not strings. Except pending, unsupported measurements may be omitted or null; enabled thresholds then stay unknown. Some brokers expose approximate counts—Monitor evaluates what the collector reports.</p>
    </x-monitor::ui.accordion>
    <x-monitor::ui.accordion title="2. Send independent worker heartbeats">
        <x-monitor::ui.code-block id="queue-example-2" language="HTTP / JSON">POST {{ route('monitor.api.queues.workers.store', ['queue' => $monitor->id]) }}
Authorization: Bearer &lt;your queue key&gt;
Content-Type: application/json

{"worker_id":"&lt;UUID for this worker boot&gt;","sequence":1,"status":"idle"}

{"worker_id":"&lt;same worker UUID&gt;","sequence":2,"status":"busy","job_id":"&lt;execution-attempt UUID&gt;"}</x-monitor::ui.code-block>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Generate a new worker UUID on each process boot and after monitor settings change or monitoring resumes. Increment sequence for each new signal (including periodic pings); reuse the sequence and payload on retry. Retries never renew liveness. Send idle, busy or stopped; only busy accepts job_id. Give every execution attempt a fresh job UUID and retain it while busy so its duration is not reset by heartbeats.</p>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Keep heartbeats running during long jobs, using a background heartbeat or sidecar. Before/after hooks alone cannot establish liveness while a job is running. Reporting failures must not change job outcomes. Durations start at server receipt of the first busy signal; no client runtime, payload, stack trace or hostname is collected.</p>
    </x-monitor::ui.accordion>
    <p class="text-xs leading-5 text-muted dark:text-subtle">Limits: 2 KiB JSON, no compression, 60 snapshot requests and 600 worker requests per minute per monitor, up to 100 live workers. Share this key only with trusted reporting processes. Pausing the environment also pauses its queue monitors; resume each monitor explicitly. Source archiving revokes queue keys. This feature does not retry, delete or inspect customer jobs.</p>
</section>
