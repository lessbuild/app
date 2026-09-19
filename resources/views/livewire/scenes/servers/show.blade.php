@php
    $displayNameDialogOpen = request()->query('dialog') === 'edit-display-name'
        || $errors->has('display_name');
    $displayNameDialogUrl = route('servers.show', ['server' => $server, 'dialog' => 'edit-display-name']);
@endphp

<div @if ($shouldPoll) wire:poll.5s @endif>

    <!--
     ! ------------------------------------------------------------
     ! Show root and other passwords
     ! ------------------------------------------------------------
     !-->
    @if(session()->has('root_password') || session()->has('mysql_password'))
        <div class="my-4">
            <x-ui.alert tone="warning">
                {{ __('The root password is:') }} <b class="font-bold">{{ session()->get('root_password') }}</b> <br>
                {{ __('The root MYSQL password is:') }} <b class="font-bold">{{ session()->get('mysql_password') }}</b> <br>
                {{ __('This will only be shown once, so please save these passwords somewhere safe.') }}
            </x-ui.alert>
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

            <x-ui.button :href="route('builds.index', ['server_id' => $server->id])" variant="secondary">
                {{ __('Deployment History') }}
            </x-ui.button>

            <x-ui.button
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
            </x-ui.button>

            <x-ui.button :href="route('servers.commands.index', $server)" variant="secondary">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#clock"></use>
                </svg>
                {{ __('Command History') }}
            </x-ui.button>

            <x-ui.button
                type="button"
                variant="primary"
                wire:click="$dispatch('open-server-command')"
                :disabled="$server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#terminal"></use>
                </svg>
                {{ __('Run Command') }}
            </x-ui.button>

            <x-dialogs.delete
                id="delete-server"
                :route="route('servers.destroy', $server)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this server?')"
            ></x-dialogs.delete>

            <button type="button" class="button button--danger" onclick="document.getElementById('delete-server').showModal()">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Server') }}
            </button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-dialogs.modal
        id="server-display-name-dialog"
        :title="__('Edit server display name')"
        :description="__('Change the label shown in BuildPusher without renaming the cloud server or its hostname.')"
        :open="$displayNameDialogOpen"
    >
        <form action="{{ route('servers.update', $server) }}" method="POST" class="space-y-5">
            @csrf
            @method('PATCH')
            <input type="hidden" name="_server_display_name_form" value="1">
            <label class="block" for="server-display-name">
                <span class="block text-sm font-semibold text-primary">{{ __('Display name') }}</span>
                <input
                    class="input secondary mt-2 w-full rounded-lg"
                    id="server-display-name"
                    name="display_name"
                    type="text"
                    maxlength="80"
                    value="{{ old('display_name', $server->display_name) }}"
                    placeholder="{{ $server->name }}"
                    autofocus
                >
                <x-forms.errors name="display_name" />
            </label>
            <div class="rounded-xl border border-primary bg-secondary p-4 text-sm text-secondary">
                <span class="font-semibold text-primary">{{ __('Cloud hostname:') }}</span>
                <code class="ml-1 break-all">{{ $server->name }}</code>
                <p class="mt-2">{{ __('Leave the display name empty to use this hostname throughout the control panel.') }}</p>
            </div>
            <x-ui.button type="submit" variant="primary">{{ __('Save display name') }}</x-ui.button>
        </form>
    </x-dialogs.modal>

    @if ($server->provisioning_status === \App\Models\Server::STATUS_FAILED)
        <x-ui.alert tone="danger" class="my-4">
            <p class="font-semibold">{{ __('Server provisioning failed') }}</p>
            <p class="text-sm">{{ $server->provisioning_error }}</p>
            @error('retry')
                <p class="mt-2 text-sm font-semibold">{{ $message }}</p>
            @enderror
            @if ($server->provisioning_failure_phase === \App\Models\Server::FAILURE_INITIALIZATION)
                <form method="POST" action="{{ route('servers.initialization.retry', $server) }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" variant="primary">{{ __('Retry initialization') }}</x-ui.button>
                </form>
            @elseif ($server->provisioning_failure_phase === \App\Models\Server::FAILURE_REMOTE)
                <form method="POST" action="{{ route('servers.provisioning.retry', $server) }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" variant="primary">{{ __('Resume provisioning') }}</x-ui.button>
                </form>
            @endif
        </x-ui.alert>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Server information
     ! ------------------------------------------------------------
     !-->
    <x-ui.card class="mt-6 p-5">
        <dl class="grid gap-5 text-sm sm:grid-cols-2 xl:grid-cols-4">
            @if (filled($server->display_name))
                <div>
                    <dt class="font-semibold text-primary">{{ __('Cloud hostname') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-secondary">{{ $server->name }}</dd>
                </div>
            @endif
            <div>
                <dt class="font-semibold text-primary">{{ __('Public IP') }}</dt>
                <dd class="mt-1 font-mono text-xs text-secondary">{{ $server->public_ip ?? __('Pending') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-primary">{{ __('Private IP') }}</dt>
                <dd class="mt-1 font-mono text-xs text-secondary">{{ $server->private_ip ?? __('Pending') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-primary">{{ __('Type') }}</dt>
                <dd class="mt-1 text-secondary">{{ str($server->type->value)->replace('-', ' ')->title() }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-primary">{{ __('Region') }}</dt>
                <dd class="mt-1 text-secondary">{{ $server->region }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-primary">{{ __('Provider') }}</dt>
                <dd class="mt-1"><a href="{{ route('providers.show', $server->provider) }}" class="text-ternary hover:underline">{{ $server->provider->name }}</a></dd>
            </div>
            <div>
                <dt class="font-semibold text-primary">{{ __('Server ID') }}</dt>
                <dd class="mt-1 font-mono text-xs text-secondary">{{ $server->identifier }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <details id="server-metrics" class="group ui-card mt-8 overflow-hidden" @if ($latestMetric === null) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Last 24 hours') }}</span>
                <span class="mt-1 block text-lg">{{ __('Server metrics') }}</span>
                <span class="mt-1 block text-sm font-normal text-secondary">
                    {{ $latestMetric?->recorded_at ? __('Latest sample :time', ['time' => $latestMetric->recorded_at->diffForHumans()]) : __('No metric samples yet.') }}
                </span>
            </span>
            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="border-t border-primary p-5" aria-labelledby="server-metrics-heading">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="server-metrics-heading" class="text-xl font-black text-primary">{{ __('Server metrics') }}</h2>
                    <p class="mt-1 text-sm text-secondary">{{ __('Load, memory, disk, and uptime collected directly from this host.') }}</p>
                </div>
                <x-ui.button type="button" variant="primary" wire:click="refreshMetrics" :disabled="$server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE">{{ __('Collect now') }}</x-ui.button>
            </div>
            <dl class="ui-insight-grid mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([['Load 1m', $latestMetric?->load_1m], ['Load 5m', $latestMetric?->load_5m], ['Memory', $latestMetric ? $latestMetric->memory_percent.'%' : null], ['Disk', $latestMetric ? $latestMetric->disk_percent.'%' : null], ['Uptime', $latestMetric ? \App\Models\Build::formatDuration($latestMetric->uptime_seconds) : null]] as [$label, $value])
                    <x-ui.stat :label="__($label)" :value="$value ?? '—'" />
                @endforeach
            </dl>
            @if ($metricHistory->isNotEmpty())
                <div class="mt-4 grid h-24 grid-flow-col items-end gap-px overflow-hidden rounded-xl border border-primary bg-secondary p-3" aria-label="{{ __('Memory utilization history') }}">
                    @foreach ($metricHistory as $metric)
                        <span class="min-w-px rounded-t bg-surface-ternary/70" style="height: {{ max(2, $metric->memory_percent) }}%" title="{{ $metric->recorded_at }} · {{ $metric->memory_percent }}%"></span>
                    @endforeach
                </div>
            @else
                <x-ui.empty-state class="mt-4" :title="__('No metric samples yet.')" :description="__('Collection runs automatically every five minutes.')" />
            @endif
        </section>
    </details>

    @php
        $diagnosticsNeedAttention = $errors->has('diagnostics')
            || $diagnosticSnapshot?->isActive()
            || $diagnosticSnapshot?->status === \App\Models\ServerDiagnosticSnapshot::STATUS_FAILED
            || ($diagnosticReport !== null && ! $diagnosticReport->passed());
        $diagnosticsOpen = $diagnosticSnapshot === null || $diagnosticsNeedAttention;
    @endphp
    <details id="server-diagnostics" class="group ui-card mt-6 overflow-hidden" @if ($diagnosticsOpen) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Troubleshooting') }}</span>
                <span class="mt-1 block text-lg">{{ __('Server diagnostics') }}</span>
                <span class="mt-1 block text-sm font-normal text-secondary">
                    @if ($diagnosticSnapshot === null)
                        {{ __('Not collected yet.') }}
                    @elseif ($diagnosticSnapshot->isActive())
                        {{ str($diagnosticSnapshot->status)->headline() }}
                    @elseif ($diagnosticSnapshot->status === \App\Models\ServerDiagnosticSnapshot::STATUS_FAILED)
                        {{ __('Last run failed.') }}
                    @elseif ($diagnosticReport !== null && ! $diagnosticReport->passed())
                        {{ __('Attention required.') }}
                    @else
                        {{ __('Latest run completed.') }}
                    @endif
                </span>
            </span>
            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="border-t border-primary p-5" aria-labelledby="server-diagnostics-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="server-diagnostics-heading" class="mt-1 text-xl font-black text-primary">{{ __('Server diagnostics') }}</h2>
                <p class="mt-1 max-w-2xl text-sm text-secondary">{{ __('Run a bounded, read-only host check using the pinned SSH identity. The probe never reads application secrets or accepts a shell command.') }}</p>
            </div>
            @can('diagnose', $server)
                <x-ui.button
                    type="button"
                    variant="primary"
                    wire:click="runDiagnostics"
                    wire:loading.attr="disabled"
                    wire:target="runDiagnostics"
                    :disabled="$server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE || $diagnosticSnapshot?->isActive()"
                >
                    <span wire:loading.remove wire:target="runDiagnostics">{{ __('Run diagnostics') }}</span>
                    <span wire:loading wire:target="runDiagnostics">{{ __('Queueing…') }}</span>
                </x-ui.button>
            @endcan
        </div>

        @if ($errors->has('diagnostics'))
            <x-ui.alert tone="danger" class="mt-4">{{ $errors->first('diagnostics') }}</x-ui.alert>
        @endif

        @if ($diagnosticSnapshot === null)
            <x-ui.empty-state class="mt-4" :title="__('No server diagnostic has been collected yet.')" />
        @elseif ($diagnosticSnapshot->status === \App\Models\ServerDiagnosticSnapshot::STATUS_QUEUED)
            <x-ui.alert tone="info" class="mt-4">{{ __('Server diagnostics are queued.') }}</x-ui.alert>
        @elseif ($diagnosticSnapshot->status === \App\Models\ServerDiagnosticSnapshot::STATUS_RUNNING)
            <x-ui.alert tone="info" class="mt-4">{{ __('Server diagnostics are running.') }}</x-ui.alert>
        @elseif ($diagnosticSnapshot->status === \App\Models\ServerDiagnosticSnapshot::STATUS_FAILED)
            <x-ui.alert tone="danger" class="mt-4">
                <p class="font-semibold">{{ __('Unable to complete server diagnostics.') }}</p>
                <p class="mt-1">{{ $diagnosticSnapshot->error ?: __('The diagnostic connection or response was unavailable.') }}</p>
                @if ($diagnosticSnapshot->finished_at)
                    <p class="mt-1 text-xs">{{ __('Last attempted :time', ['time' => $diagnosticSnapshot->finished_at->diffForHumans()]) }}</p>
                @endif
            </x-ui.alert>
        @elseif ($diagnosticReport !== null)
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($diagnosticReport->checks as $check)
                    <x-ui.card tone="muted" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-semibold text-primary">{{ $check->name }}</p>
                            @if ($check->passed)
                                <x-ui.badge tone="success">{{ __('Passed') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="danger">{{ __('Attention') }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-secondary">{{ $check->detail }}</p>
                    </x-ui.card>
                @endforeach
            </div>
            @if ($diagnosticSnapshot->finished_at)
                <p class="mt-4 text-xs text-secondary">{{ __('Collected :time · attempt :attempt', ['time' => $diagnosticSnapshot->finished_at->diffForHumans(), 'attempt' => $diagnosticSnapshot->attempt]) }}</p>
            @endif
        @endif
        </section>
    </details>

    <!-- Quick Actions -->
    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-ui.card class="self-start p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Deployments') }}</p>
                    <h2 class="mt-1 text-lg font-bold text-primary">{{ __('Attached Websites') }}</h2>
                </div>
            </div>
            <ul role="list" class="mt-4 divide-y divide-primary">
                @forelse ($websites as $website)
                    <li>
                        <a href="{{ route('websites.show', $website) }}" class="flex items-center gap-4 py-3 hover:bg-secondary">
                            <x-avatar :name="$website->name" class="h-8 w-8 shrink-0 rounded-full text-xs" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-ternary">{{ $website->name }}</span>
                                <span class="block truncate text-sm text-secondary">{{ $website->url }}</span>
                            </span>
                            <x-ui.badge tone="success">{{ __('Deployed') }}</x-ui.badge>
                        </a>
                    </li>
                @empty
                    <li class="pt-3">
                        <x-ui.alert tone="info" role="status">{{ __('No websites attached to server') }}</x-ui.alert>
                    </li>
                @endforelse
            </ul>
        </x-ui.card>

        @if ($recipes->isNotEmpty())
            <x-ui.card class="self-start p-5">
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Setup') }}</p>
                <h2 class="mt-1 text-lg font-bold text-primary">{{ __('Provisioning Recipes') }}</h2>
                <ul class="mt-4 divide-y divide-primary">
                    @foreach ($recipes as $recipe)
                        <li class="py-3">
                            <p class="text-sm font-medium text-ternary">{{ $recipe['name'] }}</p>
                            @if ($recipe['description'])
                                <p class="mt-1 text-sm text-secondary">{{ $recipe['description'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        <details
            id="server-operations"
            class="group ui-card lg:col-span-2 overflow-hidden"
            @if ($server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE || $logSnapshot?->status === \App\Models\ServerLogSnapshot::STATUS_FAILED) open @endif
        >
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-primary [&::-webkit-details-marker]:hidden">
                <span>
                    <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Operations') }}</span>
                    <span class="mt-1 block text-lg">{{ __('Logs and setup') }}</span>
                    <span class="mt-1 block text-sm font-normal text-secondary">
                        {{ __(':ready ready · :failed failed · :missing not collected', ['ready' => $logMetrics['ready'], 'failed' => $logMetrics['failed'], 'missing' => $logMetrics['missing']]) }}
                    </span>
                </span>
                <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
            </summary>
            <div class="grid gap-6 border-t border-primary p-5 lg:grid-cols-2">
                <section class="lg:col-span-2" aria-labelledby="server-log-overview-heading">
                    <div class="mb-3">
                        <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Observability') }}</p>
                        <h2 id="server-log-overview-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Log snapshot overview') }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ __('Current state across the five supported server log types.') }}</p>
                    </div>
                    <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                        <x-ui.stat :label="__('Ready snapshots')" :value="$logMetrics['ready']" />
                        <x-ui.stat :label="__('Queued snapshots')" :value="$logMetrics['queued']" />
                        <x-ui.stat :label="__('Refreshing snapshots')" :value="$logMetrics['refreshing']" />
                        <x-ui.stat :label="__('Failed snapshots')" :value="$logMetrics['failed']" />
                        <x-ui.stat :label="__('Not collected')" :value="$logMetrics['missing']" />
                        <x-ui.stat :label="__('Latest refresh')" :value="$logMetrics['latest_at']?->diffForHumans() ?? __('Not available')" />
                    </dl>
                </section>

        <!--
         ! ------------------------------------------------------------
         ! Server logs
         ! ------------------------------------------------------------
         !-->
        <section class="ui-card self-start overflow-hidden bg-slate-950 p-5 text-sm text-slate-100" aria-labelledby="server-log-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('Server logs') }}</p>
                    <h2 id="server-log-heading" class="mt-1 text-lg font-bold text-white">{{ __('Log output') }}</h2>
                </div>
                <nav class="flex flex-wrap gap-3" aria-label="{{ __('Server log types') }}">
                    <a href="?log=apt" @class(['text-xs font-medium', 'text-ternary' => $log === 'apt', 'text-slate-300' => $log !== 'apt'])>{{ __('Apt') }}</a>
                    <a href="?log=caddy" @class(['text-xs font-medium', 'text-ternary' => $log === 'caddy', 'text-slate-300' => $log !== 'caddy'])>{{ __('Caddy') }}</a>
                    <a href="?log=mysql" @class(['text-xs font-medium', 'text-ternary' => $log === 'mysql', 'text-slate-300' => $log !== 'mysql'])>{{ __('Mysql') }}</a>
                    <a href="?log=php" @class(['text-xs font-medium', 'text-ternary' => $log === 'php', 'text-slate-300' => $log !== 'php'])>{{ __('PHP') }}</a>
                    <a href="?log=provisioning" @class(['text-xs font-medium', 'text-ternary' => $log === 'provisioning', 'text-slate-300' => $log !== 'provisioning'])>{{ __('Provisioning') }}</a>
                </nav>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-700 pt-4">
                <div class="text-xs text-slate-400">
                    @if ($logSnapshot?->refreshed_at)
                        {{ __('Updated :time', ['time' => $logSnapshot->refreshed_at->diffForHumans()]) }}
                    @else
                        {{ __('No snapshot has been collected yet.') }}
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($logSnapshot?->log !== null)
                        <a href="{{ route('servers.logs.download', ['server' => $server, 'type' => $log]) }}" class="text-xs font-medium text-ternary hover:underline">{{ __('Download log') }}</a>
                    @endif
                    <button
                        type="button"
                        class="button button--primary"
                        wire:click="refreshLogs"
                        wire:loading.attr="disabled"
                        wire:target="refreshLogs"
                        @disabled($server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE || in_array($logSnapshot?->status, [\App\Models\ServerLogSnapshot::STATUS_QUEUED, \App\Models\ServerLogSnapshot::STATUS_REFRESHING], true))
                    >
                        {{ __('Refresh logs') }}
                    </button>
                </div>
            </div>
            <div class="mt-4 max-h-96 overflow-y-auto font-mono leading-5">
                @if ($server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE && $log !== 'provisioning')
                    <p class="text-slate-400">{{ __('Select Provisioning to view logs while setup is running.') }}</p>
                @else
                    @if ($errors->has('logs'))
                        <x-ui.alert tone="danger" class="mb-2">{{ $errors->first('logs') }}</x-ui.alert>
                    @elseif ($logSnapshot?->status === \App\Models\ServerLogSnapshot::STATUS_QUEUED)
                        <p class="mb-2 text-slate-400">{{ __('Log refresh queued.') }}</p>
                    @elseif ($logSnapshot?->status === \App\Models\ServerLogSnapshot::STATUS_REFRESHING)
                        <p class="mb-2 text-slate-400">{{ __('Refreshing this log snapshot…') }}</p>
                    @elseif ($logSnapshot?->status === \App\Models\ServerLogSnapshot::STATUS_FAILED)
                        <p class="mb-2 text-red-300">{{ $logSnapshot->error ?: __('Unable to retrieve logs.') }}</p>
                    @endif
                    @forelse ($logs as $line)
                        @if ($line === '') @continue @endif
                        <div class="w-full">
                            <span class="text-ternary">{{ $server->name }}:~$</span>
                            <span class="text-slate-100">{{ $line }}</span>
                        </div>
                    @empty
                        @unless (in_array($logSnapshot?->status, [\App\Models\ServerLogSnapshot::STATUS_QUEUED, \App\Models\ServerLogSnapshot::STATUS_REFRESHING], true))
                            <div class="flex">
                                <span class="text-ternary">{{ $server->name }}:~$</span>
                                <span class="flex-1 pl-2 text-slate-400">
                                    @if ($log === 'provisioning' && $server->provisioning_status !== \App\Models\Server::STATUS_ACTIVE)
                                        {{ $server->provisioning_status === \App\Models\Server::STATUS_FAILED ? __('No provisioning output was received.') : __('Waiting for provisioning output…') }}
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
        </details>
    </div>

    <div id="server-command">
        <livewire:server-command :model="$server"></livewire:server-command>
    </div>


</div>
