@php
    $displayNameDialogOpen = request()->query('dialog') === 'edit-display-name'
        || $errors->has('display_name');
    $displayNameDialogUrl = route('servers.show', ['server' => $server, 'dialog' => 'edit-display-name']);
    $commandHistoryDialogOpen = request()->query('dialog') === 'server-command-history';
    $commandHistoryDialogUrl = route('servers.show', ['server' => $server, 'dialog' => 'server-command-history']);
    $commandHistoryContentUrl = route('servers.commands.index', [
        'server' => $server,
        'fragment' => 'server-command-history',
    ]);
@endphp

<div @if ($shouldPoll) wire:poll.5s @endif>

    <!--
     ! ------------------------------------------------------------
     ! Show root and other passwords
     ! ------------------------------------------------------------
     !-->
    @if(session()->has('root_password') || session()->has('mysql_password'))
        <div class="my-4">
            <x-signal.ui.alert tone="warning">
                {{ __('The root password is:') }} <b class="font-bold">{{ session()->get('root_password') }}</b> <br>
                {{ __('The root MYSQL password is:') }} <b class="font-bold">{{ session()->get('mysql_password') }}</b> <br>
                {{ __('This will only be shown once, so please save these passwords somewhere safe.') }}
            </x-signal.ui.alert>
        </div>
    @endif


    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :route="route('servers.index')"
        :title="__('Back to servers')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        icon="digital-ocean"
        :title="$server->label"
        :description="__('Easily manage :name', ['name' => $server->label])"
    >
        <x-slot:buttons>

            <x-signal.ui.button :href="route('builds.index', ['server_id' => $server->id])" variant="secondary">
                {{ __('Deployment History') }}
            </x-signal.ui.button>

            <x-signal.ui.button
                :href="$displayNameDialogUrl"
                data-modal-trigger="server-display-name-dialog"
                aria-controls="server-display-name-dialog"
                aria-expanded="{{ $displayNameDialogOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Display Name') }}
            </x-signal.ui.button>

            <x-signal.ui.button
                :href="route('servers.commands.index', $server)"
                data-modal-trigger="server-command-history-dialog"
                data-modal-content-url="{{ $commandHistoryContentUrl }}"
                data-modal-history-url="{{ $commandHistoryDialogUrl }}"
                aria-controls="server-command-history-dialog"
                aria-expanded="{{ $commandHistoryDialogOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#clock"></use>
                </svg>
                {{ __('Command History') }}
            </x-signal.ui.button>

            <x-signal.ui.button
                type="button"
                variant="primary"
                wire:click="$dispatch('open-server-command')"
                :disabled="$server->provisioning_status !== \App\Modules\Deployer\Models\Server::STATUS_ACTIVE"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#terminal"></use>
                </svg>
                {{ __('Run Command') }}
            </x-signal.ui.button>

            <x-dialogs.delete
                id="delete-server"
                :route="route('servers.destroy', $server)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this server?')"
            ></x-dialogs.delete>

            <x-signal.ui.button variant="danger" type="button" class="ui-btn ui-btn-danger" data-modal-trigger="delete-server" aria-controls="delete-server" aria-expanded="false">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Server') }}
            </x-signal.ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-signal.ui.local-nav class="mt-6" :label="__('Server sections')">
        <a href="#server-information" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#server-metrics" class="ui-local-nav__link">{{ __('Metrics') }}</a>
        <a href="#server-diagnostics" class="ui-local-nav__link">{{ __('Diagnostics') }}</a>
        <a href="#server-operations" class="ui-local-nav__link">{{ __('Logs') }}</a>
    </x-signal.ui.local-nav>

    <x-scenes.servers.edit-dialog :server="$server" :open="$displayNameDialogOpen" />

    <x-dialogs.modal
        id="server-command-history-dialog"
        :title="__('Command history')"
        :description="__('Review recent server commands without leaving this server.')"
        :open="$commandHistoryDialogOpen"
        body-class="p-0"
        wire:ignore
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading command history…') }}</p>
        </div>
    </x-dialogs.modal>

    @if ($server->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_FAILED)
        <x-signal.ui.alert tone="danger" class="my-4">
            <p class="font-semibold">{{ __('Server provisioning failed') }}</p>
            <p class="text-sm">{{ $server->provisioning_error }}</p>
            @error('retry')
                <p class="mt-2 text-sm font-semibold">{{ $message }}</p>
            @enderror
            @if ($server->provisioning_failure_phase === \App\Modules\Deployer\Models\Server::FAILURE_INITIALIZATION)
                <form method="POST" action="{{ route('servers.initialization.retry', $server) }}" class="mt-3">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Retry initialization') }}</x-signal.ui.button>
                </form>
            @elseif ($server->provisioning_failure_phase === \App\Modules\Deployer\Models\Server::FAILURE_REMOTE)
                <form method="POST" action="{{ route('servers.provisioning.retry', $server) }}" class="mt-3">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Resume provisioning') }}</x-signal.ui.button>
                </form>
            @endif
        </x-signal.ui.alert>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Server information
     ! ------------------------------------------------------------
     !-->
    <x-signal.ui.panel as="section" id="server-information" class="ui-panel mt-6 p-5" data-server-overview>
        <dl class="grid gap-5 text-sm sm:grid-cols-2 xl:grid-cols-4">
            @if (filled($server->display_name))
                <div>
                    <dt class="font-semibold text-ink">{{ __('Cloud hostname') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-muted">{{ $server->name }}</dd>
                </div>
            @endif
            <div>
                <dt class="font-semibold text-ink">{{ __('Public IP') }}</dt>
                <dd class="mt-1 font-mono text-xs text-muted">{{ $server->public_ip ?? __('Pending') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-ink">{{ __('Private IP') }}</dt>
                <dd class="mt-1 font-mono text-xs text-muted">{{ $server->private_ip ?? __('Pending') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-ink">{{ __('Type') }}</dt>
                <dd class="mt-1 text-muted">{{ str($server->type->value)->replace('-', ' ')->title() }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-ink">{{ __('Region') }}</dt>
                <dd class="mt-1 text-muted">{{ $server->region }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-ink">{{ __('Provider') }}</dt>
                <dd class="mt-1"><a href="{{ route('providers.show', $server->provider) }}" class="ui-link">{{ $server->provider->name }}</a></dd>
            </div>
            <div>
                <dt class="font-semibold text-ink">{{ __('Server ID') }}</dt>
                <dd class="mt-1 font-mono text-xs text-muted">{{ $server->identifier }}</dd>
            </div>
        </dl>
    </x-signal.ui.panel>

    <x-signal.ui.panel as="details" id="server-metrics" class="group ui-panel mt-8 overflow-hidden" :open="$latestMetric === null" data-server-section="metrics">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Last 24 hours') }}</span>
                <span class="mt-1 block text-lg">{{ __('Server metrics') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">
                    {{ $latestMetric?->recorded_at ? __('Latest sample :time', ['time' => $latestMetric->recorded_at->diffForHumans()]) : __('No metric samples yet.') }}
                </span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="border-t border-line p-5" aria-labelledby="server-metrics-heading" data-server-metrics>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="server-metrics-heading" class="text-xl font-extrabold text-ink">{{ __('Server metrics') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Load, memory, disk, and uptime collected directly from this host.') }}</p>
                </div>
                <x-signal.ui.button type="button" variant="primary" wire:click="refreshMetrics" :disabled="$server->provisioning_status !== \App\Modules\Deployer\Models\Server::STATUS_ACTIVE">{{ __('Collect now') }}</x-signal.ui.button>
            </div>
            <dl class="ui-insight-grid mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([['Load 1m', $latestMetric?->load_1m], ['Load 5m', $latestMetric?->load_5m], ['Memory', $latestMetric ? $latestMetric->memory_percent.'%' : null], ['Disk', $latestMetric ? $latestMetric->disk_percent.'%' : null], ['Uptime', $latestMetric ? \App\Modules\Deployer\Models\Build::formatDuration($latestMetric->uptime_seconds) : null]] as [$label, $value])
                    <x-signal.ui.stat :label="__($label)" :value="$value ?? '—'" />
                @endforeach
            </dl>
            @if ($metricHistory->isNotEmpty())
                <div class="mt-4 grid h-24 grid-flow-col items-end gap-px overflow-hidden rounded-card border border-line bg-surface-muted p-3" aria-label="{{ __('Memory utilization history') }}">
                    @foreach ($metricHistory as $metric)
                        <span class="min-w-px rounded-t bg-primary/70" style="height: {{ max(2, $metric->memory_percent) }}%" title="{{ $metric->recorded_at }} · {{ $metric->memory_percent }}%"></span>
                    @endforeach
                </div>
            @else
                <x-signal.ui.empty-state class="mt-4" :title="__('No metric samples yet.')" :description="__('Collection runs automatically every five minutes.')" />
            @endif
        </section>
    </x-signal.ui.panel>

    @php
        $diagnosticsNeedAttention = $errors->has('diagnostics')
            || $diagnosticSnapshot?->isActive()
            || $diagnosticSnapshot?->status === \App\Modules\Deployer\Models\ServerDiagnosticSnapshot::STATUS_FAILED
            || ($diagnosticReport !== null && ! $diagnosticReport->passed());
        $diagnosticsOpen = $diagnosticSnapshot === null || $diagnosticsNeedAttention;
    @endphp
    <x-signal.ui.panel as="details" id="server-diagnostics" class="group ui-panel mt-6 overflow-hidden" :open="$diagnosticsOpen" data-server-section="diagnostics">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Troubleshooting') }}</span>
                <span class="mt-1 block text-lg">{{ __('Server diagnostics') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">
                    @if ($diagnosticSnapshot === null)
                        {{ __('Not collected yet.') }}
                    @elseif ($diagnosticSnapshot->isActive())
                        {{ str($diagnosticSnapshot->status)->headline() }}
                    @elseif ($diagnosticSnapshot->status === \App\Modules\Deployer\Models\ServerDiagnosticSnapshot::STATUS_FAILED)
                        {{ __('Last run failed.') }}
                    @elseif ($diagnosticReport !== null && ! $diagnosticReport->passed())
                        {{ __('Attention required.') }}
                    @else
                        {{ __('Latest run completed.') }}
                    @endif
                </span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="border-t border-line p-5" aria-labelledby="server-diagnostics-heading" data-server-diagnostics>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="server-diagnostics-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Server diagnostics') }}</h2>
                <p class="mt-1 max-w-2xl text-sm text-muted">{{ __('Run a bounded, read-only host check using the pinned SSH identity. The probe never reads application secrets or accepts a shell command.') }}</p>
            </div>
            @can('diagnose', $server)
                <x-signal.ui.button
                    type="button"
                    variant="primary"
                    wire:click="runDiagnostics"
                    wire:loading.attr="disabled"
                    wire:target="runDiagnostics"
                    :disabled="$server->provisioning_status !== \App\Modules\Deployer\Models\Server::STATUS_ACTIVE || $diagnosticSnapshot?->isActive()"
                >
                    <span wire:loading.remove wire:target="runDiagnostics">{{ __('Run diagnostics') }}</span>
                    <span wire:loading wire:target="runDiagnostics">{{ __('Queueing…') }}</span>
                </x-signal.ui.button>
            @endcan
        </div>

        @if ($errors->has('diagnostics'))
            <x-signal.ui.alert tone="danger" class="mt-4">{{ $errors->first('diagnostics') }}</x-signal.ui.alert>
        @endif

        @if ($diagnosticSnapshot === null)
            <x-signal.ui.empty-state class="mt-4" :title="__('No server diagnostic has been collected yet.')" />
        @elseif ($diagnosticSnapshot->status === \App\Modules\Deployer\Models\ServerDiagnosticSnapshot::STATUS_QUEUED)
            <x-signal.ui.alert tone="info" class="mt-4">{{ __('Server diagnostics are queued.') }}</x-signal.ui.alert>
        @elseif ($diagnosticSnapshot->status === \App\Modules\Deployer\Models\ServerDiagnosticSnapshot::STATUS_RUNNING)
            <x-signal.ui.alert tone="info" class="mt-4">{{ __('Server diagnostics are running.') }}</x-signal.ui.alert>
        @elseif ($diagnosticSnapshot->status === \App\Modules\Deployer\Models\ServerDiagnosticSnapshot::STATUS_FAILED)
            <x-signal.ui.alert tone="danger" class="mt-4">
                <p class="font-semibold">{{ __('Unable to complete server diagnostics.') }}</p>
                <p class="mt-1">{{ $diagnosticSnapshot->error ?: __('The diagnostic connection or response was unavailable.') }}</p>
                @if ($diagnosticSnapshot->finished_at)
                    <p class="mt-1 text-xs">{{ __('Last attempted :time', ['time' => $diagnosticSnapshot->finished_at->diffForHumans()]) }}</p>
                @endif
            </x-signal.ui.alert>
        @elseif ($diagnosticReport !== null)
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($diagnosticReport->checks as $check)
                    <x-signal.ui.panel as="article" class="ui-panel bg-surface-muted p-4">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-semibold text-ink">{{ $check->name }}</p>
                            @if ($check->passed)
                                <x-signal.ui.badge tone="success">{{ __('Passed') }}</x-signal.ui.badge>
                            @else
                                <x-signal.ui.badge tone="danger">{{ __('Attention') }}</x-signal.ui.badge>
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-muted">{{ $check->detail }}</p>
                    </x-signal.ui.panel>
                @endforeach
            </div>
            @if ($diagnosticSnapshot->finished_at)
                <p class="mt-4 text-xs text-muted">{{ __('Collected :time · attempt :attempt', ['time' => $diagnosticSnapshot->finished_at->diffForHumans(), 'attempt' => $diagnosticSnapshot->attempt]) }}</p>
            @endif
        @endif
        </section>
    </x-signal.ui.panel>

    <!-- Quick Actions -->
    <div class="mt-8 grid gap-6 lg:grid-cols-2" data-server-supporting-surfaces>
        <x-signal.ui.panel as="section" class="ui-panel self-start p-5" data-server-websites>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Deployments') }}</p>
                    <h2 class="mt-1 text-lg font-bold text-ink">{{ __('Attached Websites') }}</h2>
                </div>
            </div>
            <ul role="list" class="mt-4 divide-y divide-line">
                @forelse ($websites as $website)
                    <li>
                        <a href="{{ route('websites.show', $website) }}" class="flex items-center gap-4 py-3 transition hover:bg-surface-muted">
                            <x-avatar :name="$website->name" class="ui-avatar-sm shrink-0 text-xs" />
                            <span class="min-w-0 flex-1">
                                <span class="ui-link block truncate text-sm">{{ $website->name }}</span>
                                <span class="block truncate text-sm text-muted">{{ $website->url }}</span>
                            </span>
                            <x-signal.ui.badge tone="success">{{ __('Deployed') }}</x-signal.ui.badge>
                        </a>
                    </li>
                @empty
                    <li class="pt-3">
                            <li class="ui-panel border-l-4 border-line bg-surface-muted p-3 text-sm text-muted" style="border-left-color: var(--ui-primary)" role="status">{{ __('No websites attached to server') }}</li>
                    </li>
                @endforelse
            </ul>
        </x-signal.ui.panel>

        @if ($recipes->isNotEmpty())
            <x-signal.ui.panel as="section" class="ui-panel self-start p-5" data-server-recipes>
                <p class="ui-eyebrow">{{ __('Provisioning') }}</p>
                <h2 class="mt-1 text-lg font-bold text-ink">{{ __('Provisioning Recipes') }}</h2>
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($recipes as $recipe)
                        <li class="py-3">
                            <p class="ui-link text-sm">{{ $recipe['name'] }}</p>
                            @if ($recipe['description'])
                                <p class="mt-1 text-sm text-muted">{{ $recipe['description'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-signal.ui.panel>
        @endif

        <x-signal.ui.panel as="details"
            id="server-operations"
            class="group ui-panel lg:col-span-2 overflow-hidden"
            data-server-section="operations"
            :open="$server->provisioning_status !== \App\Modules\Deployer\Models\Server::STATUS_ACTIVE || $logSnapshot?->status === \App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_FAILED"
        >
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
                <span>
                    <span class="ui-eyebrow block">{{ __('Operations') }}</span>
                    <span class="mt-1 block text-lg">{{ __('Logs and setup') }}</span>
                    <span class="mt-1 block text-sm font-normal text-muted">
                        {{ __(':ready ready · :failed failed · :missing not collected', ['ready' => $logMetrics['ready'], 'failed' => $logMetrics['failed'], 'missing' => $logMetrics['missing']]) }}
                    </span>
                </span>
                <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
            </summary>
            <div class="grid gap-6 border-t border-line p-5 lg:grid-cols-2" data-server-operations-content>
                <section class="lg:col-span-2" aria-labelledby="server-log-overview-heading">
                    <div class="mb-3">
                        <p class="ui-eyebrow">{{ __('Observability') }}</p>
                        <h2 id="server-log-overview-heading" class="mt-1 text-lg font-bold text-ink">{{ __('Log snapshot overview') }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ __('Current state across the five supported server log types.') }}</p>
                    </div>
                    <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                        <x-signal.ui.stat :label="__('Ready snapshots')" :value="$logMetrics['ready']" />
                        <x-signal.ui.stat :label="__('Queued snapshots')" :value="$logMetrics['queued']" />
                        <x-signal.ui.stat :label="__('Refreshing snapshots')" :value="$logMetrics['refreshing']" />
                        <x-signal.ui.stat :label="__('Failed snapshots')" :value="$logMetrics['failed']" />
                        <x-signal.ui.stat :label="__('Not collected')" :value="$logMetrics['missing']" />
                        <x-signal.ui.stat :label="__('Latest refresh')" :value="$logMetrics['latest_at']?->diffForHumans() ?? __('Not available')" />
                    </dl>
                </section>

        <!--
         ! ------------------------------------------------------------
         ! Server logs
         ! ------------------------------------------------------------
         !-->
        <section class="ui-console self-start p-5 text-sm" aria-labelledby="server-log-heading" data-server-log-console>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-emphasis-muted">{{ __('Server logs') }}</p>
                    <h2 id="server-log-heading" class="mt-1 text-lg font-bold text-emphasis-ink">{{ __('Log output') }}</h2>
                </div>
                <nav class="flex flex-wrap gap-3" aria-label="{{ __('Server log types') }}">
                    <a href="?log=apt" @class(['ui-link text-xs' => $log === 'apt', 'text-xs font-medium text-emphasis-muted hover:text-emphasis-ink' => $log !== 'apt'])>{{ __('Apt') }}</a>
                    <a href="?log=caddy" @class(['ui-link text-xs' => $log === 'caddy', 'text-xs font-medium text-emphasis-muted hover:text-emphasis-ink' => $log !== 'caddy'])>{{ __('Caddy') }}</a>
                    <a href="?log=mysql" @class(['ui-link text-xs' => $log === 'mysql', 'text-xs font-medium text-emphasis-muted hover:text-emphasis-ink' => $log !== 'mysql'])>{{ __('Mysql') }}</a>
                    <a href="?log=php" @class(['ui-link text-xs' => $log === 'php', 'text-xs font-medium text-emphasis-muted hover:text-emphasis-ink' => $log !== 'php'])>{{ __('PHP') }}</a>
                    <a href="?log=provisioning" @class(['ui-link text-xs' => $log === 'provisioning', 'text-xs font-medium text-emphasis-muted hover:text-emphasis-ink' => $log !== 'provisioning'])>{{ __('Provisioning') }}</a>
                </nav>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                <div class="text-xs text-emphasis-muted">
                    @if ($logSnapshot?->refreshed_at)
                        {{ __('Updated :time', ['time' => $logSnapshot->refreshed_at->diffForHumans()]) }}
                    @else
                        {{ __('No snapshot has been collected yet.') }}
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($logSnapshot?->log !== null)
                        <a href="{{ route('servers.logs.download', ['server' => $server, 'type' => $log]) }}" class="ui-link text-xs">{{ __('Download log') }}</a>
                    @endif
                    <x-signal.ui.button variant="primary"
                        type="button"
                        class="ui-btn ui-btn-primary"
                        wire:click="refreshLogs"
                        wire:loading.attr="disabled"
                        wire:target="refreshLogs"
                        :disabled="$server->provisioning_status !== \App\Modules\Deployer\Models\Server::STATUS_ACTIVE || in_array($logSnapshot?->status, [\App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_QUEUED, \App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_REFRESHING], true)"
                    >
                        {{ __('Refresh logs') }}
                    </x-signal.ui.button>
                </div>
            </div>
            <div class="mt-4 max-h-96 overflow-y-auto font-mono leading-5">
                @if ($server->provisioning_status !== \App\Modules\Deployer\Models\Server::STATUS_ACTIVE && $log !== 'provisioning')
                    <p class="text-emphasis-muted">{{ __('Select Provisioning to view logs while setup is running.') }}</p>
                @else
                    @if ($errors->has('logs'))
                        <x-signal.ui.alert tone="danger" class="mb-2">{{ $errors->first('logs') }}</x-signal.ui.alert>
                    @elseif ($logSnapshot?->status === \App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_QUEUED)
                        <p class="mb-2 text-emphasis-muted">{{ __('Log refresh queued.') }}</p>
                    @elseif ($logSnapshot?->status === \App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_REFRESHING)
                        <p class="mb-2 text-emphasis-muted">{{ __('Refreshing this log snapshot…') }}</p>
                    @elseif ($logSnapshot?->status === \App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_FAILED)
                        <p class="mb-2 text-danger">{{ $logSnapshot->error ?: __('Unable to retrieve logs.') }}</p>
                    @endif
                    @forelse ($logs as $line)
                        @if ($line === '') @continue @endif
                        <div class="w-full">
                            <span class="text-[var(--ui-primary)]">{{ $server->name }}:~$</span>
                            <span class="text-emphasis-ink">{{ $line }}</span>
                        </div>
                    @empty
                        @unless (in_array($logSnapshot?->status, [\App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_QUEUED, \App\Modules\Deployer\Models\ServerLogSnapshot::STATUS_REFRESHING], true))
                            <div class="flex">
                                <span class="text-[var(--ui-primary)]">{{ $server->name }}:~$</span>
                                <span class="flex-1 pl-2 text-emphasis-muted">
                                    @if ($log === 'provisioning' && $server->provisioning_status !== \App\Modules\Deployer\Models\Server::STATUS_ACTIVE)
                                        {{ $server->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_FAILED ? __('No provisioning output was received.') : __('Waiting for provisioning output…') }}
                                    @else
                                        {{ __('No logs to show') }}
                                    @endif
                                </span>
                            </div>
                        @endunless
                    @endforelse
                @endif
            </div>
        </section>

            </div>
        </x-signal.ui.panel>
    </div>

    <div id="server-command">
        <livewire:server-command :model="$server"></livewire:server-command>
    </div>


</div>
