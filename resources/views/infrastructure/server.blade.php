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
            {{-- Setup moves on in the background; reload so the page follows it. --}}
            @push('head')<meta http-equiv="refresh" content="15">@endpush
            <x-signal.ui.progress :value="$server->setup_stage" :max="$finalStage" :label="__('Provisioning progress')" />
        @endif
        @if ($server->provisioning_status === 'waiting_for_ip')
            <p class="text-sm text-muted">
                {{ __('Waiting for the provider to start the server and give it an address, then for SSH to answer. This usually takes a minute or two; we keep checking for up to twenty minutes.') }}
                @if ($server->provisioning_error)
                    <span class="block mt-1">{{ __('Latest check: :reason', ['reason' => $server->provisioning_error]) }}</span>
                @endif
            </p>
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

    <x-signal.ui.page-tabs :tabs="$tabs" :current="$tab" :url="route('infrastructure.servers.show', [$project, $server->id])" />

    <x-signal.ui.page-tab-panel name="overview" :current="$tab">
        @if ($server->provisioning_status === 'active')
        @php($latest = $metrics->last())
        <x-signal.ui.card class="grid gap-4 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-extrabold text-ink">{{ __('Resources') }}</h2>
                <div class="flex flex-wrap gap-2">
                    <x-signal.ui.button :href="route('infrastructure.servers.commands', [$project, $server->id])" variant="secondary" size="sm">{{ __('Commands') }}</x-signal.ui.button>
                    @if ($canOpenTerminal && $server->ssh_host_key)
                        <form method="POST" action="{{ route('infrastructure.servers.terminal.store', [$project, $server->id]) }}" data-terminal-open>
                            @csrf
                            <input type="hidden" name="columns" value="120">
                            <input type="hidden" name="rows" value="32">
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Open terminal') }}</x-signal.ui.button>
                        </form>
                    @endif
                </div>
            </div>
            @error('terminal')<p class="text-sm text-danger">{{ $message }}</p>@enderror
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
        @else
            <x-signal.ui.card class="p-5 text-sm text-muted">{{ __('Resources, alerts and diagnostics appear once the server is active.') }}</x-signal.ui.card>
        @endif
    </x-signal.ui.page-tab-panel>

    @if ($server->provisioning_status === 'active')

        <x-signal.ui.page-tab-panel name="alerts" :current="$tab">
        <x-signal.ui.settings-section id="alerts" :title="__('Alerts')" :description="__('Owners and admins get an email and an inbox message when a reading stays past a threshold, and when it recovers.')">
            <div class="grid gap-4 p-4 sm:p-6">
                @if ($alertRules->isEmpty())
                    <p class="text-sm text-muted">{{ __('No alerts yet.') }}</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($alertRules as $rule)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span><span class="font-bold text-ink">{{ $rule->name }}</span> <span class="text-muted">{{ __(\App\Models\ServerAlertRule::METRICS[$rule->metric] ?? $rule->metric) }} {{ $rule->operator === 'lte' ? '≤' : '≥' }} {{ rtrim(rtrim(number_format($rule->threshold, 2), '0'), '.') }} · {{ trans_choice(':count reading|:count readings in a row', $rule->consecutive_breaches, ['count' => $rule->consecutive_breaches]) }} · {{ $rule->server_id === null ? __('all servers') : __('this server') }}</span></span>
                                <span class="flex items-center gap-2">
                                    @if ($rule->is_alerting)<x-signal.ui.badge tone="danger">{{ __('Alerting') }}</x-signal.ui.badge>@endif
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('infrastructure.servers.alerts.destroy', [$project, $server->id, $rule->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <form method="POST" action="{{ route('infrastructure.servers.alerts.store', [$project, $server->id]) }}" class="grid items-end gap-3 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-3">
                        @csrf
                        <x-signal.ui.input-field name="name" :label="__('Name')" placeholder="Disk almost full" maxlength="120" required />
                        <x-signal.ui.select-field name="metric" :label="__('Metric')">
                            @foreach (\App\Models\ServerAlertRule::METRICS as $key => $label)
                                <option value="{{ $key }}">{{ __($label) }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <div class="flex gap-2">
                            <x-signal.ui.select-field name="operator" :label="__('When')"><option value="gte">≥</option><option value="lte">≤</option></x-signal.ui.select-field>
                            <x-signal.ui.input-field name="threshold" type="number" step="0.01" :label="__('Threshold')" value="90" required />
                        </div>
                        <x-signal.ui.input-field name="consecutive_breaches" type="number" min="1" max="20" :label="__('Readings in a row')" value="3" required />
                        <x-signal.ui.input-field name="cooldown_minutes" type="number" min="5" max="1440" :label="__('Quiet for (minutes)')" value="60" required />
                        <x-signal.ui.select-field name="scope" :label="__('Applies to')"><option value="server">{{ __('This server') }}</option><option value="account">{{ __('Every server') }}</option></x-signal.ui.select-field>
                        <div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Add alert') }}</x-signal.ui.button></div>
                    </form>
                @endif
            </div>
        </x-signal.ui.settings-section>
        </x-signal.ui.page-tab-panel>

        <x-signal.ui.page-tab-panel name="diagnostics" :current="$tab">
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
        </x-signal.ui.page-tab-panel>
    @endif

    <x-signal.ui.page-tab-panel name="logs" :current="$tab">
    <x-signal.ui.settings-section :title="__('Logs')" :description="$log?->refreshed_at ? __('Fetched :time', ['time' => $log->refreshed_at->diffForHumans()]) : __('The last 200 lines of each log.')">
        <div class="grid gap-3 p-4 sm:p-6">
            <nav aria-label="{{ __('Logs') }}" class="flex flex-wrap gap-2">
                @foreach ($logTypes as $type)
                    <x-signal.ui.button :href="route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'logs', 'log' => $type])" :variant="$logType === $type ? 'soft' : 'quiet'" size="sm" :aria-current="$logType === $type ? 'page' : null">{{ ['apt' => 'APT', 'caddy' => 'Caddy', 'mysql' => 'MySQL', 'php' => 'PHP-FPM', 'provisioning' => __('Provisioning')][$type] ?? $type }}</x-signal.ui.button>
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
    </x-signal.ui.page-tab-panel>

    @if ($canManage)
    <x-signal.ui.page-tab-panel name="settings" :current="$tab">
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
    </x-signal.ui.page-tab-panel>
    @endif
</x-signal.layouts.project>
