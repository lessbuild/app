@extends('monitor::layouts.app')
@section('title', $environment->name.' · '.$application->name)
@section('breadcrumb', 'Environment setup')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.applications.show', $application) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← {{ $application->name }}</a>
    <x-monitor::ui.page-header :eyebrow="$application->framework.' · '.$application->name" :title="$environment->name" description="Connect a collector, confirm receipt, and manage its credentials.">
        <x-slot:actions><x-monitor::ui.badge :tone="$environment->trashed() || $environment->status === 'paused' ? 'amber' : 'slate'">{{ $environment->trashed() ? 'Archived' : ucfirst($environment->status) }}</x-monitor::ui.badge></x-slot:actions>
    </x-monitor::ui.page-header>
    @if($environment->trashed())
        <section class="ui-alert ui-alert-warning block p-6">
            <h2 class="font-bold">Archived environment</h2><p class="mt-2 text-sm text-muted dark:text-subtle">Its events are preserved. Restore it, then create a fresh token to collect new events.</p>
            @if($canRestore)<form method="POST" action="{{ route('monitor.environments.restore', [$application, $environment]) }}" class="mt-4">@csrf<x-monitor::ui.button>Restore environment</x-monitor::ui.button></form>@endif
        </section>
    @else
        @unless($application->trashed())<a href="{{ route('monitor.deployments.index', [$application, $environment]) }}" class="inline-block text-xs font-bold text-primary hover:underline dark:text-primary">Deployment history →</a>@endunless
        @if($secret)
            <section class="ui-alert border-primary/30 bg-primary-soft block p-6">
                <h2 class="font-bold text-primary dark:text-primary">Save your new token now</h2><p class="mt-2 text-sm text-primary dark:text-primary">This is the only time the full token will be shown. Store it in your application's secret manager, never in source control or browser code.</p>
                <div class="mt-4 flex flex-col gap-3 sm:flex-row"><input id="issued-token" type="text" readonly autocomplete="off" aria-label="New ingestion token" value="{{ $secret }}" class="ui-input min-w-0 flex-1 font-mono"><x-monitor::ui.button type="button" data-copy-target="issued-token"><span data-copy-label>Copy token</span></x-monitor::ui.button></div>
            </section>
        @endif
        <section data-connection-watch data-connection-url="{{ route('monitor.environments.connection', [$application, $environment]) }}" data-initial-count="{{ $environment->event_count }}" class="ui-panel p-6">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><h2 class="font-bold">Connection check</h2><p data-connection-status role="status" class="mt-2 text-sm text-muted dark:text-subtle">{{ $environment->status === 'paused' ? 'Ingestion is paused. Resume it in environment settings.' : ($environment->last_seen_at ? 'Events have been received. Send another event to verify your current setup.' : 'Waiting for your first event. Send the request below to verify your connection.') }}</p></div><x-monitor::ui.button type="button" variant="secondary" data-check-connection>Check now</x-monitor::ui.button></div>
            <div class="mt-5 grid gap-4 border-t border-line pt-5 sm:grid-cols-2 dark:border-line"><div><p class="text-xs text-subtle">Events processed</p><p data-connection-count class="mt-1 text-xl font-bold">{{ number_format($environment->event_count) }}</p></div><div><p class="text-xs text-subtle">Last processed delivery received</p><p data-connection-time class="mt-1 text-sm font-semibold">{{ $environment->last_seen_at?->toIso8601String() ?? 'Not yet' }}</p></div></div>
        </section>
        @if($canManage)
            @if(parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https')<p class="ui-alert ui-alert-warning block p-4 text-xs leading-5 text-warning dark:text-warning">This preview uses HTTP. Configure HTTPS before sending production credentials or sensitive telemetry.</p>@endif
            <section class="ui-panel overflow-hidden">
                <div class="border-b border-line p-6 dark:border-line"><h2 class="font-bold">Send your first event</h2><p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">Set BEACON_TOKEN in your terminal or secret manager, then send this JSON request. Keep the same batch ID, event IDs and payload when retrying a delivery. Use a new batch ID for new data.</p></div>
                <div class="p-6"><div class="mb-3 flex items-center justify-between"><span class="text-xs font-bold">cURL · works with any stack</span><x-monitor::ui.button type="button" variant="secondary" data-copy-target="connection-command"><span data-copy-label>Copy command</span></x-monitor::ui.button></div>
<pre class="library-code"><code id="connection-command">curl --request POST '{{ route('monitor.api.ingest') }}' \
  --header "Authorization: Bearer ${BEACON_TOKEN}" \
  --header 'Content-Type: application/json' \
  --data '{{ $samplePayload }}'</code></pre>
                    <p class="mt-4 text-xs leading-5 text-muted dark:text-subtle">HTTP 202 means a JSON delivery is retained for background processing, not yet searchable. Check its receipt for completion; HTTP 200 indicates completed processing or a completed replay. OTLP keeps its HTTP 200 response and reports processing state in X-Beacon-Status. Exporters can use the HTTP/JSON signal endpoints below; protobuf and gRPC are not accepted yet.</p>
                    <div class="mt-4 grid gap-3 lg:grid-cols-3">@foreach(['traces', 'logs', 'metrics'] as $signal)<div class="rounded-control bg-surface-muted p-3 dark:bg-surface-muted"><p class="text-xs font-bold capitalize">{{ $signal }}</p><code class="mt-2 block break-all text-[11px] text-muted dark:text-subtle">{{ route('monitor.api.otlp', $signal) }}</code></div>@endforeach</div>
                    <p class="mt-4 text-xs leading-5 text-muted dark:text-subtle">JSON responses include a receipt_id; OTLP responses include X-Beacon-Receipt. Read a receipt with the same environment's active token using GET <code class="break-all">{{ route('monitor.api.ingest.receipts.show', 'RECEIPT_ID') }}</code>. OTLP exports without timestamps can look identical: send a unique X-Beacon-Batch for each new export and reuse it unchanged on retries.</p>
                </div>
            </section>
            <section class="ui-panel overflow-hidden">
                <div class="border-b border-line p-6 dark:border-line"><h2 class="font-bold">Ingestion tokens</h2><p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">For a change without downtime, create a second token, update your collectors, then revoke the old one. Immediate rotation revokes the old key at once and keeps its original expiry date.</p></div>
                <div class="divide-y divide-line dark:divide-line">
                    @forelse($tokens as $token)
                        <div class="flex flex-col justify-between gap-4 px-6 py-4 xl:flex-row xl:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-semibold">{{ $token->name }}</h3><x-monitor::ui.badge :tone="$token->status() === 'active' ? 'green' : 'slate'">{{ ucfirst($token->status()) }}</x-monitor::ui.badge></div>
                                <p class="mt-2 font-mono text-xs text-muted dark:text-subtle">{{ $token->prefix }}…</p>
                                <p class="mt-1 text-xs text-subtle">Created by {{ $token->creator?->name ?? 'Imported or removed user' }} · {{ $token->created_at->toDateTimeString() }}</p>
                                <p class="mt-1 text-xs text-subtle">Last used: {{ $token->last_used_at?->diffForHumans() ?? 'Never' }} · Expires: {{ $token->expires_at?->toDateTimeString() ?? 'No automatic expiry' }}</p>
                            </div>
                            @if($token->status() === 'active')<div class="flex flex-wrap gap-2"><form method="POST" action="{{ route('monitor.ingest-tokens.rotate', [$application, $environment, $token]) }}" data-confirm="The old token will stop working immediately. Rotate it now?">@csrf<x-monitor::ui.button variant="secondary">Rotate immediately</x-monitor::ui.button></form><form method="POST" action="{{ route('monitor.ingest-tokens.destroy', [$application, $environment, $token]) }}" data-confirm="Collectors using this token will stop sending events. Revoke it?">@csrf @method('DELETE')<x-monitor::ui.button variant="secondary">Revoke</x-monitor::ui.button></form></div>@endif
                        </div>
                    @empty
                        <p class="px-6 py-8 text-sm text-muted dark:text-subtle">No keys yet. Create a token below to connect this environment.</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('monitor.ingest-tokens.store', [$application, $environment]) }}" class="grid items-end gap-4 border-t border-line p-6 sm:grid-cols-[1fr_1fr_auto] dark:border-line">
                    @csrf
                    <x-monitor::ui.input name="name" id="token-name" label="Token name" placeholder="Production collector" maxlength="120" required />
                    <x-monitor::ui.input name="expires_in_days" type="number" label="Expires in days (optional)" placeholder="No automatic expiry" min="1" max="365" />
                    <x-monitor::ui.button>Create token</x-monitor::ui.button>
                </form>
                @if($tokens->hasPages())<div class="border-t border-line p-6 dark:border-line">{{ $tokens->links() }}</div>@endif
            </section>
        @endif
    @endif
    <section class="ui-panel overflow-hidden">
        <div class="flex flex-col justify-between gap-3 border-b border-line px-6 py-4 sm:flex-row sm:items-center dark:border-line">
            <div><h2 class="font-bold">Delivery receipts</h2><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Latest six accepted batches. Refresh this page for updates. Rejected requests and empty OTLP exports are not recorded here.</p></div>
            <a href="{{ route('monitor.environments.ingestion', [$application, $environment]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">Ingestion diagnostics</a>
        </div>
        @if($recentReceipts->isNotEmpty())
            <div class="overflow-x-auto">
                <x-monitor::ui.table caption="Counts describe the original delivery. Attempts include successful replays; replays do not add usage." :framed="false">
                    <x-slot:head>
                        <tr><th scope="col" class="px-6 font-semibold">Receipt / received UTC</th><th scope="col" class="font-semibold">Source / status</th><th scope="col" class="text-right font-semibold">New</th><th scope="col" class="text-right font-semibold">Duplicates</th><th scope="col" class="px-6 text-right font-semibold">Attempts</th></tr>
                    </x-slot:head>
                        @foreach($recentReceipts as $receipt)
                            <tr>
                                <th scope="row" class="px-6 font-normal"><code class="whitespace-nowrap text-[11px]">{{ $receipt->id }}</code><time datetime="{{ $receipt->received_at->toISOString() }}" class="mt-1 block whitespace-nowrap text-muted dark:text-subtle">{{ $receipt->received_at->utc()->format('Y-m-d H:i:s') }}</time></th>
                                <td><p class="whitespace-nowrap font-semibold">{{ $receipt->source->label() }}</p><p class="mt-1 text-muted dark:text-subtle">{{ $receipt->status->label() }}</p></td>
                                <td class="text-right tabular-nums">{{ number_format($receipt->accepted_count) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($receipt->duplicate_count) }}</td>
                                <td class="px-6 text-right tabular-nums">{{ number_format($receipt->attempt_count) }}</td>
                            </tr>
                        @endforeach
                </x-monitor::ui.table>
            </div>
        @else
            <p class="px-6 py-8 text-sm text-muted dark:text-subtle">No delivery receipts yet. Earlier telemetry stays available below; receipts start with your next non-empty delivery.</p>
        @endif
    </section>
    <section class="ui-panel overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-line px-6 py-4 dark:border-line">
            <h2 class="font-bold">Recently received</h2>
            @unless($environment->trashed() || $application->trashed())<a href="{{ route('monitor.events.index', ['application' => $application->id, 'environment' => $environment->id, 'range' => 'all']) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">Explore events</a>@endunless
        </div>
        <div class="divide-y divide-line dark:divide-line">
            @forelse($recentEvents as $event)
                <div class="flex flex-col justify-between gap-2 px-6 py-4 sm:flex-row">
                    <div>
                        <p class="text-sm font-semibold">
                            @unless($environment->trashed() || $application->trashed())<a href="{{ route('monitor.events.show', $event->id) }}" class="hover:text-primary dark:hover:text-primary">{{ $event->name ?? ucfirst($event->type) }}</a>@else{{ $event->name ?? ucfirst($event->type) }}@endunless
                        </p>
                        <p class="mt-1 text-xs text-muted dark:text-subtle">{{ $event->service ?? $event->type }}</p>
                    </div>
                    <p class="text-xs text-subtle">{{ $event->created_at->diffForHumans() }}</p>
                </div>
            @empty
                <p class="px-6 py-8 text-sm text-muted dark:text-subtle">No events received yet.</p>
            @endforelse
        </div>
    </section>
    @if($canManage)
        <div class="grid gap-6 xl:grid-cols-2">
            <section class="ui-panel p-6">
                <h2 class="mb-5 font-bold">Environment settings</h2>
                <form method="POST" action="{{ route('monitor.environments.update', [$application, $environment]) }}" class="space-y-4">@csrf @method('PATCH')
                    <x-monitor::ui.input name="name" id="environment-name" label="Name" :value="$environment->name" maxlength="120" required />
                    <x-monitor::ui.input name="slug" label="Identifier" :value="$environment->slug" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
                    <x-monitor::ui.select id="environment-status" name="status" label="Ingestion" :value="$environment->status" :options="['active' => 'Active', 'paused' => 'Paused']" description="Pausing rejects new requests without revoking keys. Existing active keys work again after resuming." />
                    <x-monitor::ui.button>Save environment</x-monitor::ui.button>
                </form>
            </section>
            <section class="ui-panel shadow-none border-danger bg-surface p-6 dark:border-danger dark:bg-surface">
                <h2 class="font-bold text-danger dark:text-danger">Archive environment</h2><p class="mt-2 text-sm leading-6 text-muted dark:text-subtle">Stops ingestion and revokes all its tokens. Historical events remain stored.</p>
                <form method="POST" action="{{ route('monitor.environments.destroy', [$application, $environment]) }}" class="mt-5 space-y-4">@csrf @method('DELETE')
                    <x-monitor::ui.input name="confirmation" :label="'Type '.$environment->name.' to confirm'" autocomplete="off" required />
                    <x-monitor::ui.button variant="secondary">Archive environment</x-monitor::ui.button>
                </form>
            </section>
        </div>
    @endif
</div>
@endsection
