@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$server->label()" :description="$server->type->label().($server->public_ip ? ' · '.$server->public_ip : '').($server->provider ? ' · '.$server->provider->type->label().($server->region ? ' '.$server->region : '') : ' · '.__('Imported'))">
    @if (session('error'))
        <x-signal.ui.alert tone="danger" role="alert">{{ session('error') }}</x-signal.ui.alert>
    @endif
    @foreach (['retry', 'server'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    @include('infrastructure._secrets')

    <x-signal.ui.card class="grid gap-4 p-5">
        <div class="flex flex-wrap items-center gap-3">
            @include('infrastructure._status', ['server' => $server])
            @if ($server->isProvisioning())
                <span class="text-sm text-muted">{{ __('Stage :stage of :final', ['stage' => $server->setup_stage, 'final' => $finalStage]) }}</span>
            @elseif ($server->provisioned_at)
                <span class="text-sm text-muted">{{ __('Provisioned :time', ['time' => $server->provisioned_at->diffForHumans()]) }}</span>
            @endif
        </div>
        @if ($server->isProvisioning())
            <x-signal.ui.progress :value="$server->setup_stage" :max="$finalStage" :label="__('Provisioning progress')" />
        @endif
        @if ($server->provisioning_status === 'failed')
            <p class="text-sm text-danger">{{ $server->provisioning_error }}</p>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @if ($server->provisioning_failure_phase === 'initialization')
                        <form method="POST" action="{{ route('infrastructure.servers.initialization.retry', [$project, $server->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary">{{ __('Retry initialisation') }}</x-signal.ui.button></form>
                    @elseif ($server->provisioning_failure_phase === 'remote')
                        <form method="POST" action="{{ route('infrastructure.servers.provisioning.retry', [$project, $server->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary">{{ __('Retry provisioning') }}</x-signal.ui.button></form>
                    @else
                        <p class="text-sm text-muted">{{ __('Creation failed and nothing was left running. Delete this server and try again.') }}</p>
                    @endif
                </div>
            @endif
        @endif
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-muted">{{ __('SSH') }}</dt><dd class="mt-1 font-mono">{{ 'root@'.($server->public_ip ?? '—').':'.$server->ssh_port }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Host key') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $server->ssh_host_fingerprint ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Size · image') }}</dt><dd class="mt-1">{{ $server->size ?? '—' }} · {{ $server->image ?? '—' }}</dd></div>
        </dl>
    </x-signal.ui.card>

    @if ($server->provisioning_status === 'active')
        @php($latest = $metrics->last())
        <x-signal.ui.card class="grid gap-4 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-extrabold text-ink">{{ __('Resources') }}</h2>
                <x-signal.ui.button :href="route('infrastructure.servers.commands', [$project, $server->id])" variant="secondary" size="sm">{{ __('Commands') }}</x-signal.ui.button>
            </div>
            @if ($latest === null)
                <p class="text-sm text-muted">{{ __('Metrics are collected every five minutes. The first reading appears shortly.') }}</p>
            @else
                <dl class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ __('CPU') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ $latest->cpu_percent }}%</dd></div>
                    <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ __('Memory') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ $latest->memory_percent }}%</dd></div>
                    <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ __('Disk') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ $latest->disk_percent }}%</dd></div>
                    <div class="rounded-control bg-surface-muted p-3"><dt class="text-xs text-muted">{{ __('Load (1 min)') }}</dt><dd class="mt-1 text-xl font-extrabold text-ink">{{ number_format($latest->load_1m, 2) }}</dd></div>
                </dl>
                <x-signal.ui.bar-chart :label="__('CPU use over the last 24 hours')" :points="$metrics->map(fn ($metric) => ['label' => $metric->recorded_at->format('H:i').' UTC', 'value' => $metric->cpu_percent])->values()->all()" unit="%" />
                <p class="text-xs text-muted">{{ __('Last reading :time · up :days days · :processes processes', ['time' => $latest->recorded_at->diffForHumans(), 'days' => intdiv($latest->uptime_seconds, 86400), 'processes' => $latest->process_count]) }}</p>
            @endif
        </x-signal.ui.card>

        <x-signal.ui.settings-section id="diagnostics" :title="__('Diagnostics')" :description="$diagnostics?->finished_at ? __('Last run :time', ['time' => $diagnostics->finished_at->diffForHumans()]) : __('A read-only check of SSH, root access, PHP, storage, disk, memory and processes.')">
            <div class="grid gap-3 p-4 sm:p-6">
                @if ($diagnostics?->isRunning())
                    <p class="text-sm text-muted">{{ __('Running… refresh in a few seconds.') }}</p>
                @elseif ($diagnostics?->checks)
                    <ul class="grid gap-2">
                        @foreach ($diagnostics->checks as $check)
                            <li class="flex flex-wrap items-center justify-between gap-2 text-sm"><span class="font-bold text-ink">{{ __($check['name']) }}</span><span class="flex items-center gap-2 text-muted">{{ $check['detail'] }} <x-signal.ui.badge :tone="$check['passed'] ? 'success' : 'danger'">{{ $check['passed'] ? __('OK') : __('Problem') }}</x-signal.ui.badge></span></li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('infrastructure.servers.diagnostics', [$project, $server->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Run diagnostics') }}</x-signal.ui.button></form>
            </div>
        </x-signal.ui.settings-section>
    @endif

    <x-signal.ui.settings-section :title="__('Logs')" :description="$log?->refreshed_at ? __('Fetched :time', ['time' => $log->refreshed_at->diffForHumans()]) : __('The last 200 lines of each log.')">
        <div class="grid gap-3 p-4 sm:p-6">
            <nav aria-label="{{ __('Logs') }}" class="flex flex-wrap gap-2">
                @foreach ($logTypes as $type)
                    <x-signal.ui.button :href="route('infrastructure.servers.show', [$project, $server->id, 'log' => $type])" :variant="$logType === $type ? 'soft' : 'quiet'" size="sm" :aria-current="$logType === $type ? 'page' : null">{{ ['apt' => 'APT', 'caddy' => 'Caddy', 'mysql' => 'MySQL', 'php' => 'PHP-FPM', 'provisioning' => __('Provisioning')][$type] ?? $type }}</x-signal.ui.button>
                @endforeach
            </nav>
            @if ($log?->error)
                <x-signal.ui.alert tone="danger">{{ $log->error }}</x-signal.ui.alert>
            @endif
            @if ($log?->log)
                <x-signal.ui.code-block class="max-h-96 overflow-auto whitespace-pre-wrap" :code="$log->log" />
            @elseif (in_array($log?->status, ['queued', 'refreshing'], true))
                <p class="text-sm text-muted">{{ __('Fetching… refresh in a few seconds.') }}</p>
            @endif
            @if ($server->provisioning_status === 'active')
                <form method="POST" action="{{ route('infrastructure.servers.logs.refresh', [$project, $server->id, $logType]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Fetch the latest') }}</x-signal.ui.button></form>
            @endif
        </div>
    </x-signal.ui.settings-section>

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Name')" :description="__('Shown in the app. The server’s hostname stays :name.', ['name' => $server->name])">
            <form method="POST" action="{{ route('infrastructure.servers.update', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                @csrf
                @method('PUT')
                <x-signal.ui.input-field name="display_name" :label="__('Display name')" :value="$server->display_name" maxlength="80" />
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Delete this server')" :description="$server->provider ? __(':provider deletes the machine and everything on it, and the SSH key we made.', ['provider' => $server->provider->type->label()]) : __('The server is forgotten here; nothing on it is changed.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="delete-server">{{ __('Delete server') }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="delete-server" :route="route('infrastructure.servers.destroy', [$project, $server->id])" :title="__('Delete :server?', ['server' => $server->label()])" :description="$server->provider ? __('The machine and its data are deleted at the provider. This can’t be undone.') : __('Nothing on the server is changed.')" :submit-label="__('Delete server')" />
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
