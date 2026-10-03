{{-- The Replicas tab of a database server: its read replicas, or the primary it copies, with promotion. --}}
@php
    $statusTones = ['setting_up' => 'info', 'streaming' => 'success', 'broken' => 'danger', 'failed' => 'danger'];
    $statusLabels = ['setting_up' => __('Copying'), 'streaming' => __('Streaming'), 'broken' => __('Stopped'), 'failed' => __('Setup failed')];
@endphp
@if ($server->replicaOf)
    <x-signal.ui.settings-section id="replica" :title="__('Read replica')" :description="__('This server copies :primary continuously and serves reads only. Point read-only queries and reports here to take load off the primary.', ['primary' => $server->replicaOf->name])">
        <div class="grid gap-4 p-4 sm:p-6">
            <p class="text-sm">
                <x-signal.ui.badge :tone="$statusTones[$server->replication_status] ?? 'neutral'">{{ $statusLabels[$server->replication_status] ?? $server->replication_status }}</x-signal.ui.badge>
                @if ($server->replication_status === 'streaming')
                    {{ $server->replication_lag_seconds === null ? __('Lag unknown') : trans_choice(':count second behind|:count seconds behind', $server->replication_lag_seconds) }}
                @endif
                @if ($server->replication_checked_at)<span class="text-muted">· {{ __('checked :time', ['time' => $server->replication_checked_at->diffForHumans()]) }}</span>@endif
            </p>
            @if ($server->replication_error)
                <x-signal.ui.alert tone="danger">{{ $server->replication_error }}</x-signal.ui.alert>
            @endif
        </div>
    </x-signal.ui.settings-section>
    @if ($canRunCommands)
        <x-signal.ui.settings-section id="promote" :title="__('Promote to a standalone server')" :description="__('Stops following the primary and starts accepting writes, keeping everything copied so far. Use it when the primary has failed, then point your applications here.')">
            <form method="POST" action="{{ route('infrastructure.servers.replicas.promote', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                @csrf
                <x-signal.ui.input-field name="confirmation" :label="__('Type :name to confirm', ['name' => $server->name])" autocomplete="off" required />
                <x-signal.ui.button type="submit" variant="danger">{{ __('Promote') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.settings-section>
    @endif
@else
    <x-signal.ui.settings-section id="replicas" :title="__('Read replicas')" :description="__('Other database servers in your cloud account that copy this one continuously and serve reads, over the private network when they share one and TLS otherwise.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @forelse ($replicas as $replica)
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-ink"><a href="{{ route('infrastructure.servers.show', [$project, $replica->id, 'tab' => 'replicas']) }}">{{ $replica->name }}</a></p>
                        <p class="text-xs text-muted">
                            <span class="font-mono">{{ $replica->private_ip ?? $replica->public_ip }}</span>
                            @if ($replica->replication_status === 'streaming')
                                · {{ $replica->replication_lag_seconds === null ? __('Lag unknown') : trans_choice(':count second behind|:count seconds behind', $replica->replication_lag_seconds) }}
                            @endif
                        </p>
                        @if ($replica->replication_error)<p class="text-xs text-danger">{{ $replica->replication_error }}</p>@endif
                    </div>
                    <x-signal.ui.badge :tone="$statusTones[$replica->replication_status] ?? 'neutral'">{{ $statusLabels[$replica->replication_status] ?? $replica->replication_status }}</x-signal.ui.badge>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No read replicas yet.') }}</p>
            @endforelse
            @if ($replicas->isNotEmpty())
                <div class="grid gap-2">
                    <p class="text-sm text-muted">{{ __('In a Laravel app, send reads to the replicas in config/database.php:') }}</p>
                    @php
                        $readHosts = $replicas->map(fn ($replica): string => "'".($replica->private_ip ?? $replica->public_ip)."'")->implode(', ');
                        $readConfig = "'read' => ['host' => [{$readHosts}]],\n'write' => ['host' => ['".($server->private_ip ?? $server->public_ip)."']],\n'sticky' => true,";
                    @endphp
                    <x-signal.ui.code-block :code="$readConfig" />
                </div>
            @endif
        </div>
    </x-signal.ui.settings-section>
    @if ($canRunCommands)
        <x-signal.ui.settings-section id="add-replica" :title="__('Add a read replica')" :description="__('Choose another :engine server in this account. Its current data is moved aside on the server and replaced by a copy of this one; large databases take a while to copy.', ['engine' => $server->database_engine === 'postgres' ? 'PostgreSQL' : 'MySQL'])">
            <div class="p-4 sm:p-6">
                @if ($replicaCandidates->isEmpty())
                    <p class="text-sm text-muted">{{ __('Create another database server with the same engine first, ideally in the same region.') }}</p>
                @else
                    <form method="POST" action="{{ route('infrastructure.servers.replicas.store', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <x-signal.ui.select-field name="replica_server_id" :label="__('Replica')">
                            @foreach ($replicaCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}{{ $candidate->region ? ' · '.$candidate->region : '' }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field name="confirmation" :label="__('Type the replica’s name to confirm')" autocomplete="off" required />
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Add replica') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>
    @endif
@endif
