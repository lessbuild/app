@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$server->label()" :description="$server->type->label().($server->public_ip ? ' · '.$server->public_ip : '').($server->provider ? ' · '.$server->provider->type->label().($server->region ? ' '.$server->region : '') : ' · '.__('Imported'))">
    @if (session('error'))
        <x-signal.ui.alert tone="danger" role="alert">{{ session('error') }}</x-signal.ui.alert>
    @endif
    @foreach (['retry', 'server'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    @include('infrastructure._secrets')

    {{-- While setup runs, resources/js/server-status.js follows it live through the status endpoint. --}}
    <x-signal.ui.card class="grid gap-4 p-5" :data-server-status="$server->isProvisioning() ? route('infrastructure.servers.status', [$project, $server->id]) : null">
        <div class="flex flex-wrap items-center gap-3">
            <span data-server-status-badge>@include('infrastructure._status', ['server' => $server])</span>
            @if ($server->isProvisioning())
                <span class="text-sm text-muted" data-server-status-stage data-template="{{ __('Stage :stage of :final', ['stage' => '__stage__', 'final' => '__final__']) }}">{{ __('Stage :stage of :final', ['stage' => $server->setup_stage, 'final' => $finalStage]) }}</span>
                <span class="inline-flex items-center gap-2 text-sm font-semibold text-ink" data-server-status-step aria-live="polite">
                    <span class="size-2 animate-pulse rounded-full bg-primary" aria-hidden="true"></span>
                    <span data-server-status-step-text>{{ $server->provisioning_status === 'provisioning' ? $currentStep : '' }}</span>
                </span>
            @elseif ($server->provisioned_at)
                <span class="text-sm text-muted">{{ __('Provisioned :time', ['time' => $server->provisioned_at->diffForHumans()]) }}</span>
            @endif
        </div>
        @if ($server->isProvisioning())
            <x-signal.ui.progress :value="$server->setup_stage" :max="$finalStage" :label="__('Provisioning progress')" data-server-status-progress />
        @endif
        @if ($server->isProvisioning())
            <p class="text-sm text-muted" data-server-status-waiting @unless ($server->provisioning_status === 'waiting_for_ip') hidden @endunless>
                {{ __('Waiting for the provider to start the server and give it an address, then for SSH to answer. This usually takes a minute or two; we keep checking for up to twenty minutes.') }}
                <span class="mt-1 block" data-server-status-reason data-template="{{ __('Latest check: :reason', ['reason' => '__reason__']) }}">{{ $server->provisioning_status === 'waiting_for_ip' && $server->provisioning_error ? __('Latest check: :reason', ['reason' => $server->provisioning_error]) : '' }}</span>
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
            <div><dt class="text-xs text-muted">{{ __('SSH') }}</dt><dd class="mt-1 font-mono" data-server-status-ssh>{{ 'root@'.($server->public_ip ?? '—').':'.$server->ssh_port }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Host key') }}</dt><dd class="mt-1 break-all font-mono text-xs" data-server-status-host-key>{{ $server->ssh_host_fingerprint ?? '—' }}</dd></div>
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
                    @can('runCommands', $server)
                        <x-signal.ui.button :href="route('infrastructure.servers.commands', [$project, $server->id])" variant="secondary" size="sm" data-modal-trigger="run-command">{{ __('Run a command') }}</x-signal.ui.button>
                    @endcan
                    <x-signal.ui.button :href="route('infrastructure.servers.commands', [$project, $server->id])" variant="quiet" size="sm">{{ __('Command history') }}</x-signal.ui.button>
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
            @can('runCommands', $server)
                <x-signal.overlays.modal id="run-command" :title="__('Run a command on :server', ['server' => $server->label()])" :description="__('Runs as root over SSH. You’ll see its output on the commands page as soon as it finishes.')">
                    <form method="POST" action="{{ route('infrastructure.servers.commands.store', [$project, $server->id]) }}" class="grid gap-4">
                        @csrf
                        <input type="hidden" name="_modal" value="run-command">
                        <x-signal.ui.textarea-field id="run-command-text" name="command" :label="__('Command')" rows="3" maxlength="4096" class="font-mono" placeholder="systemctl status caddy --no-pager" required />
                        <div class="flex justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Run as root') }}</x-signal.ui.button></div>
                    </form>
                </x-signal.overlays.modal>
            @endcan
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
                    <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-server-alert'])" variant="secondary" size="sm" data-modal-trigger="add-server-alert">{{ __('Add an alert') }}</x-signal.ui.button></div>
                    <x-signal.overlays.modal id="add-server-alert" :title="__('Add an alert')" :description="__('Get told when CPU, memory, disk or load stays above a threshold.')">
                        <form method="POST" action="{{ route('infrastructure.servers.alerts.store', [$project, $server->id]) }}" class="grid items-end gap-3 sm:grid-cols-3">
                            @csrf
                            <input type="hidden" name="_modal" value="add-server-alert">
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
                            <div class="flex justify-end sm:col-span-3"><x-signal.ui.button type="submit" variant="primary">{{ __('Add alert') }}</x-signal.ui.button></div>
                        </form>
                    </x-signal.overlays.modal>
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
            <x-signal.ui.settings-section id="disk" :title="__('Disk clean-up')" :description="__('What’s taking space that can be cleared safely: releases beyond the ones each website keeps (never the live one), logs older than two weeks, package caches, unused Docker images and old temporary files.')">
                <div class="grid gap-3 p-4 sm:p-6">
                    @php($disk = $diskScan?->findings['disk'] ?? null)
                    @if ($disk)
                        <p class="text-sm text-muted">{{ __(':free free of :size', ['free' => \Illuminate\Support\Number::fileSize($disk['items']), 'size' => \Illuminate\Support\Number::fileSize($disk['bytes'])]) }} · {{ __('measured :time', ['time' => $diskScan->scanned_at?->diffForHumans()]) }}</p>
                    @endif
                    @if ($diskScan?->error)<x-signal.ui.alert tone="danger">{{ $diskScan->error }}</x-signal.ui.alert>@endif
                    @if (in_array($diskScan?->status, ['queued', 'running'], true))<p class="text-sm text-muted">{{ __('Working… refresh in a moment.') }}</p>@endif
                    @if ($diskScan?->findings)
                        <ul class="grid gap-2 text-sm">
                            @foreach (\App\Services\Infrastructure\DiskCleanup::CATEGORIES as $category => $label)
                                @php($found = $diskScan->findings[$category] ?? ['bytes' => 0, 'items' => 0])
                                <li class="flex flex-wrap items-center justify-between gap-3">
                                    <span><span class="font-bold text-ink">{{ __($label) }}</span> <span class="text-muted">· {{ \Illuminate\Support\Number::fileSize($found['bytes']) }}</span></span>
                                    @if ($canRunCommands && $found['bytes'] > 0)
                                        <form method="POST" action="{{ route('infrastructure.servers.disk', [$project, $server->id]) }}">@csrf<input type="hidden" name="clean" value="{{ $category }}"><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Clear') }}</x-signal.ui.button></form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($canRunCommands)
                        <form method="POST" action="{{ route('infrastructure.servers.disk', [$project, $server->id]) }}">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ $diskScan ? __('Measure again') : __('Measure the disk') }}</x-signal.ui.button></form>
                    @endif
                </div>
            </x-signal.ui.settings-section>
        </x-signal.ui.page-tab-panel>
    @endif

    @if (isset($tabs['recovery']))
        <x-signal.ui.page-tab-panel name="recovery" :current="$tab">
        <x-signal.ui.settings-section id="continuous-backup" :title="__('Continuous backup')" :description="__('A daily base backup plus the :log, kept in a backup destination, so :engine can be restored to any moment in the window. Uses WAL-G.', ['log' => $server->database_engine === 'postgres' ? __('write-ahead log (sent as it’s written)') : __('binary log (sent every five minutes)'), 'engine' => $server->database_engine === 'postgres' ? 'PostgreSQL' : 'MySQL'])">
            <div class="grid gap-4 p-4 sm:p-6">
                @if ($recoveryPlan)
                    <p class="text-sm">
                        <x-signal.ui.badge tone="success">{{ __('On') }}</x-signal.ui.badge>
                        {{ __('Backing up to :destination, keeping :days days. Restorable from :from UTC.', ['destination' => $recoveryPlan->destination->name, 'days' => $recoveryPlan->retention_days, 'from' => $recoveryPlan->earliestRestore()->utc()->format('Y-m-d H:i')]) }}
                        @if ($recoveryPlan->setupExecution)<span class="text-muted">· {{ __('Setup: :status', ['status' => $recoveryPlan->setupExecution->status]) }}</span>@endif
                    </p>
                @endif
                @if ($canRunCommands)
                    @if ($backupDestinations->isEmpty())
                        <p class="text-sm text-muted">{{ __('Add an S3-compatible backup destination under Infrastructure → Backups first.') }}</p>
                    @else
                        <form method="POST" action="{{ route('infrastructure.servers.database-recovery', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <x-signal.ui.select-field name="backup_destination_id" :label="__('Destination')">
                                @foreach ($backupDestinations as $destination)
                                    <option value="{{ $destination->id }}" @selected($recoveryPlan?->backup_destination_id === $destination->id)>{{ $destination->name }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.input-field name="retention_days" type="number" min="1" max="35" :label="__('Keep (days)')" :value="$recoveryPlan->retention_days ?? 7" />
                            <x-signal.ui.button type="submit" variant="secondary">{{ $recoveryPlan ? __('Set up again') : __('Turn on continuous backup') }}</x-signal.ui.button>
                        </form>
                    @endif
                @endif
            </div>
        </x-signal.ui.settings-section>
        @if ($recoveryPlan && $canRunCommands)
            <x-signal.ui.settings-section id="restore" :title="__('Restore to a point in time')" :description="__('Stops the database, moves its current data aside (kept on the server), restores the last base backup before the moment and replays the log up to it. Applications see the database as it was then.')">
                <form method="POST" action="{{ route('infrastructure.servers.database-recovery.restore', [$project, $server->id]) }}" class="grid items-end gap-4 p-4 sm:grid-cols-3 sm:p-6">
                    @csrf
                    <x-signal.ui.input-field name="restore_to" type="datetime-local" step="1" :label="__('Restore to (UTC)')" required />
                    <x-signal.ui.input-field name="confirmation" :label="__('Type :name to confirm', ['name' => $server->name])" autocomplete="off" required />
                    <x-signal.ui.button type="submit" variant="danger">{{ __('Restore') }}</x-signal.ui.button>
                </form>
            </x-signal.ui.settings-section>
        @endif
        </x-signal.ui.page-tab-panel>
    @endif

    @foreach (['cron' => '_cron', 'processes' => '_processes', 'firewall' => '_firewall', 'services' => '_services'] as $name => $partial)
        @if (isset($tabs[$name]))
            <x-signal.ui.page-tab-panel :name="$name" :current="$tab">
                @include('infrastructure.server.'.$partial)
            </x-signal.ui.page-tab-panel>
        @endif
    @endforeach

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

    <x-signal.ui.settings-section id="log-shipping" :title="__('Send logs to Monitoring')" :description="__('A small agent on the server sends system warnings and errors, and its websites’ Laravel warnings and errors with their stack traces, to Monitoring, where you can search them beside traces and alert on them. Up to 600 lines a minute.')">
        <div class="grid gap-3 p-4 sm:p-6">
            @if ($logShipping)
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <x-signal.ui.badge :tone="match ($logShipping->status) { 'active' => 'success', 'failed' => 'danger', default => 'neutral' }">{{ __(ucfirst($logShipping->status)) }}</x-signal.ui.badge>
                    <span class="text-muted">{{ __('To :project · :environment', ['project' => $logShipping->environment->project->name, 'environment' => $logShipping->environment->name]) }}</span>
                    @if ($logShipping->status === 'active')
                        <a class="font-bold text-primary hover:underline" href="{{ route('monitoring.events', [$logShipping->environment->project_id, 'environment' => $logShipping->environment_id, 'type' => 'log']) }}">{{ __('See the logs') }}</a>
                    @endif
                </p>
                @if ($logShipping->last_error)<x-signal.ui.alert tone="danger">{{ $logShipping->last_error }}</x-signal.ui.alert>@endif
            @endif
            @if ($canManage && $server->provisioning_status === \App\Models\Server::STATUS_ACTIVE)
                @if ($logEnvironments->isEmpty())
                    <p class="text-sm text-muted">{{ __('Turn on Monitoring for a project first.') }}</p>
                @else
                    <form method="POST" action="{{ route('infrastructure.servers.log-shipping', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3">
                        @csrf @method('PUT')
                        <x-signal.ui.select-field name="environment_id" :label="__('Send to')">
                            @foreach ($logEnvironments as $environment)
                                <option value="{{ $environment->id }}" @selected($logShipping?->environment_id === $environment->id)>{{ $environment->project->name }} · {{ $environment->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary">{{ $logShipping ? __('Reinstall') : __('Start sending') }}</x-signal.ui.button>
                    </form>
                    @if ($logShipping && $logShipping->status !== 'removing')
                        <form method="POST" action="{{ route('infrastructure.servers.log-shipping', [$project, $server->id]) }}">
                            @csrf @method('DELETE')
                            <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Stop sending') }}</x-signal.ui.button>
                        </form>
                    @endif
                @endif
            @endif
        </div>
    </x-signal.ui.settings-section>
    </x-signal.ui.page-tab-panel>

    @if ($canManage)
    <x-signal.ui.page-tab-panel name="settings" :current="$tab">
        @if ($server->provider_id !== null && $server->provisioning_status === \App\Models\Server::STATUS_ACTIVE)
            <x-signal.ui.settings-section id="snapshots" :title="__('Snapshots before risky changes')" :description="__('Take a provider snapshot before updates are installed, security fixes run or the Node.js version changes, keeping the three newest. Restore one from your provider’s dashboard if a change goes wrong. Your provider charges for snapshot storage.')">
                <div class="grid gap-3 p-4 sm:p-6">
                    <form method="POST" action="{{ route('infrastructure.servers.snapshots', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3">
                        @csrf @method('PUT')
                        <x-signal.ui.checkbox name="snapshot_before_changes" value="1" unchecked-value="0" :checked="$server->snapshot_before_changes">{{ __('Snapshot before risky changes') }}</x-signal.ui.checkbox>
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                    </form>
                    @foreach ($snapshots as $snapshot)
                        <p class="text-sm"><x-signal.ui.badge :tone="$snapshot->status === 'taken' ? 'success' : 'danger'">{{ $snapshot->status === 'taken' ? __('Taken') : __('Failed') }}</x-signal.ui.badge> <span class="text-muted">{{ $snapshot->reason }} · {{ $snapshot->created_at?->diffForHumans() }}</span>@if ($snapshot->error) <span class="text-xs text-danger">{{ $snapshot->error }}</span>@endif</p>
                    @endforeach
                    <form method="POST" action="{{ route('infrastructure.servers.snapshots', [$project, $server->id]) }}">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Take a snapshot now') }}</x-signal.ui.button></form>
                </div>
            </x-signal.ui.settings-section>
        @endif
        @if (in_array('node', $server->type->installs(), true) && $server->provisioning_status === \App\Models\Server::STATUS_ACTIVE)
            <x-signal.ui.settings-section id="node-version" :title="__('Node.js version')" :description="__('Node.js is installed for the whole server, so switching changes it for every website and build on it.')">
                <form method="POST" action="{{ route('infrastructure.servers.node-version', [$project, $server->id]) }}" class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                    @csrf
                    @method('PUT')
                    <x-signal.ui.select-field name="node_version" :label="__('Node.js')">
                        @foreach (\App\Actions\Infrastructure\ChangeRuntimeVersion::NODE_VERSIONS as $version)
                            <option value="{{ $version }}" @selected($server->node_version === $version)>Node.js {{ $version }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Switch') }}</x-signal.ui.button>
                </form>
            </x-signal.ui.settings-section>
        @endif
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
