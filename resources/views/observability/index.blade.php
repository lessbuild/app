<x-layouts.app>
    @php
        $metricRuleDialogHasErrors = old('_metric_rule_form') === '1'
            && $errors->hasAny(['name', 'server_id', 'metric', 'operator', 'threshold', 'consecutive_breaches', 'cooldown_minutes']);
        $metricRuleDialogOpen = (request()->query('dialog') === 'create-metric-rule' && ! session()->has('success'))
            || $metricRuleDialogHasErrors;
        $metricRuleDialogUrl = route('observability.index', ['dialog' => 'create-metric-rule']);
    @endphp

    <x-layouts.partials.heading
        eyebrow="{{ __('Operations') }}"
        icon="chip"
        :title="__('Observability')"
        :description="__('Metrics, runtime logs, alert integrations, and public service health in one place.')"
    >
        @if ($canManage)
            <x-slot:buttons>
                <x-ui.button
                    href="{{ $metricRuleDialogUrl }}"
                    data-modal-trigger="metric-rule-dialog"
                    aria-controls="metric-rule-dialog"
                    aria-expanded="{{ $metricRuleDialogOpen ? 'true' : 'false' }}"
                    variant="primary"
                >
                    {{ __('Create alert rule') }}
                </x-ui.button>
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    @php
        $activeOperationalIncidentCount = $operationalIncidents
            ->reject(fn ($incident) => $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED)
            ->count();
        $recentHealthFailureCount = $correlatedHealthChecks->count();
    @endphp

    <x-ui.insights
        id="observability-overview"
        class="mt-6 scroll-mt-24 border-ternary"
        :summary="trans_choice(':count active incident|:count active incidents', $activeOperationalIncidentCount, ['count' => $activeOperationalIncidentCount])"
        aria-labelledby="observability-overview-title"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Response overview') }}</p>
                <h2 id="observability-overview-title" class="mt-1 text-xl font-black text-primary">{{ __('Start with what needs attention') }}</h2>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-secondary">{{ __('Review active response work and recent signals first, then open the supporting telemetry and communication controls.') }}</p>
            </div>
            <x-ui.badge tone="accent">{{ __('Workspace scope') }}</x-ui.badge>
        </div>

        <div class="ui-insight-grid mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <a href="#operational-incidents" class="ui-card ui-card--interactive block bg-secondary p-4">
                <p class="text-xs font-bold uppercase tracking-widest text-secondary">{{ __('Active response') }}</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ $activeOperationalIncidentCount }}</p>
                <p class="mt-1 text-sm text-secondary">{{ trans_choice(':count incident|:count incidents', $activeOperationalIncidentCount, ['count' => $activeOperationalIncidentCount]) }}</p>
            </a>
            <a href="#correlated-signals" class="ui-card ui-card--interactive block bg-secondary p-4">
                <p class="text-xs font-bold uppercase tracking-widest text-secondary">{{ __('Recent health signals') }}</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ $recentHealthFailureCount }}</p>
                <p class="mt-1 text-sm text-secondary">{{ trans_choice(':count failed check|:count failed checks', $recentHealthFailureCount, ['count' => $recentHealthFailureCount]) }}</p>
            </a>
            <a href="#server-telemetry" class="ui-card ui-card--interactive block bg-secondary p-4">
                <p class="text-xs font-bold uppercase tracking-widest text-secondary">{{ __('Infrastructure') }}</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ $servers->count() }}</p>
                <p class="mt-1 text-sm text-secondary">{{ trans_choice(':count monitored server|:count monitored servers', $servers->count(), ['count' => $servers->count()]) }}</p>
            </a>
            <a href="#status-pages" class="ui-card ui-card--interactive block bg-secondary p-4">
                <p class="text-xs font-bold uppercase tracking-widest text-secondary">{{ __('Communication') }}</p>
                <p class="mt-2 text-2xl font-black text-primary">{{ $statusPages->count() }}</p>
                <p class="mt-1 text-sm text-secondary">{{ trans_choice(':count status page|:count status pages', $statusPages->count(), ['count' => $statusPages->count()]) }}</p>
            </a>
        </div>

        <x-ui.local-nav class="mt-5" :label="__('Observability sections')">
            <a href="#operational-incidents" class="ui-local-nav__link">{{ __('Incidents') }}</a>
            <a href="#server-telemetry" class="ui-local-nav__link">{{ __('Telemetry') }}</a>
            <a href="#correlated-signals" class="ui-local-nav__link">{{ __('Deployment signals') }}</a>
            <a href="#alert-destinations" class="ui-local-nav__link">{{ __('Alert destinations') }}</a>
            <a href="#status-pages" class="ui-local-nav__link">{{ __('Status pages') }}</a>
            <a href="#status-incident-timeline" class="ui-local-nav__link">{{ __('Status updates') }}</a>
        </x-ui.local-nav>
    </x-ui.insights>

    @include('observability._operational-incidents')

    <section id="server-telemetry" class="ui-card mt-8 scroll-mt-24 p-6" aria-labelledby="server-telemetry-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Infrastructure') }}</p>
                <h2 id="server-telemetry-title" class="mt-1 text-xl font-black text-primary">{{ __('Server telemetry') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ __('CPU, memory, disk, load, network and process history with threshold alerts.') }}</p>
            </div>
            <x-ui.badge>{{ __('30-day retention · 5-minute samples') }}</x-ui.badge>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($servers as $server)
                @php
                    $metric = $server->metrics->first();
                @endphp
                <article class="rounded-xl border border-primary bg-secondary p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="truncate font-black text-primary">{{ $server->label }}</h3>
                        <span class="shrink-0 text-xs text-secondary">{{ $metric?->recorded_at?->diffForHumans() ?? __('Awaiting sample') }}</span>
                    </div>
                    <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="ui-card ui-card--muted p-2"><dd class="text-lg font-black text-primary">{{ $metric?->cpu_percent ?? '—' }}@if ($metric)%@endif</dd><dt class="text-[10px] uppercase text-secondary">CPU</dt></div>
                        <div class="ui-card ui-card--muted p-2"><dd class="text-lg font-black text-primary">{{ $metric?->memory_percent ?? '—' }}@if ($metric)%@endif</dd><dt class="text-[10px] uppercase text-secondary">RAM</dt></div>
                        <div class="ui-card ui-card--muted p-2"><dd class="text-lg font-black text-primary">{{ $metric?->disk_percent ?? '—' }}@if ($metric)%@endif</dd><dt class="text-[10px] uppercase text-secondary">Disk</dt></div>
                    </dl>
                    <div class="mt-3 flex h-10 items-end gap-0.5" aria-label="{{ __('Recent CPU samples') }}">
                        @foreach ($server->metrics->reverse() as $sample)
                            <span class="min-w-1 flex-1 rounded-t bg-surface-ternary/60" style="height: {{ max(4, $sample->cpu_percent ?? 0) }}%" title="{{ $sample->cpu_percent }}%"></span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-secondary">{{ __('Load :load · :processes processes · ↓ :rx / ↑ :tx', ['load' => $metric?->load_1m ?? '—', 'processes' => $metric?->process_count ?? '—', 'rx' => $metric ? Number::fileSize($metric->network_rx_bytes ?? 0) : '—', 'tx' => $metric ? Number::fileSize($metric->network_tx_bytes ?? 0) : '—']) }}</p>
                </article>
            @empty
                <x-ui.empty-state class="md:col-span-2 xl:col-span-3" :title="__('No servers available')" :description="__('Provision a server to begin collecting telemetry.')" icon="server" />
            @endforelse
        </div>

        @if ($canManage)
            <details id="metric-alert-rules" class="mt-6 rounded-xl border border-primary bg-primary p-4" @if ($errors->any()) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md font-bold text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                    <span>{{ __('Metric alert rules') }}</span>
                    <span class="flex items-center gap-2">
                        @if ($metricRules->isNotEmpty())
                            <x-ui.badge>{{ $metricRules->count() }}</x-ui.badge>
                        @endif
                        <span class="text-secondary" aria-hidden="true">⌄</span>
                    </span>
                </summary>
                <div class="mt-5 grid gap-5 border-t border-primary pt-6 lg:grid-cols-[1fr_22rem]">
                <div class="space-y-2">
                    <div class="mb-3">
                        <h3 class="font-bold text-primary">{{ __('Metric alert rules') }}</h3>
                        <p class="mt-1 text-xs text-secondary">{{ __('Trigger a notification after a sustained threshold breach.') }}</p>
                    </div>
                    @foreach ($metricRules as $rule)
                        <div class="flex items-center gap-3 rounded-xl border border-primary bg-secondary p-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-primary">{{ $rule->name }}</p>
                                <p class="text-xs text-secondary">{{ $rule->server?->label ?? __('All servers') }} · {{ str($rule->metric)->replace('_', ' ')->headline() }} {{ $rule->operator === 'gte' ? '≥' : '≤' }} {{ $rule->threshold }}</p>
                            </div>
                            <x-ui.badge tone="{{ $rule->is_alerting ? 'danger' : 'neutral' }}">{{ $rule->is_alerting ? __('Alerting') : __('Watching') }}</x-ui.badge>
                            <form method="POST" action="{{ route('observability.metric-rules.destroy', $rule) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger" aria-label="{{ __('Delete :name', ['name' => $rule->name]) }}">{{ __('Delete') }}</x-ui.button>
                            </form>
                        </div>
                    @endforeach
                </div>

                </div>
            </details>
            <x-dialogs.modal
                id="metric-rule-dialog"
                :title="__('Create an alert rule')"
                :description="__('Trigger a notification after a sustained metric threshold breach.')"
                :open="$metricRuleDialogOpen"
            >
                <form method="POST" action="{{ route('observability.metric-rules.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="_metric_rule_form" value="1">
                    <label class="block">
                        <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Name') }}</span>
                        <input name="name" value="{{ old('name') }}" required class="input secondary w-full rounded-md" placeholder="High memory">
                        <x-forms.errors name="name" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Server') }}</span>
                        <select name="server_id" class="input secondary w-full rounded-md">
                            <option value="">{{ __('All servers') }}</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected((string) old('server_id') === (string) $server->id)>{{ $server->label }}</option>
                            @endforeach
                        </select>
                        <x-forms.errors name="server_id" />
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="sr-only">{{ __('Metric') }}</span>
                            <select name="metric" class="input secondary w-full rounded-md" aria-label="{{ __('Metric') }}">
                                @foreach (\App\Models\MetricAlertRule::METRICS as $metric)
                                    <option value="{{ $metric }}" @selected(old('metric', 'cpu_percent') === $metric)>{{ str($metric)->replace('_', ' ')->headline() }}</option>
                                @endforeach
                            </select>
                            <x-forms.errors name="metric" />
                        </label>
                        <label class="block">
                            <span class="sr-only">{{ __('Operator') }}</span>
                            <select name="operator" class="input secondary w-full rounded-md" aria-label="{{ __('Operator') }}">
                                <option value="gte" @selected(old('operator', 'gte') === 'gte')>≥</option>
                                <option value="lte" @selected(old('operator') === 'lte')>≤</option>
                            </select>
                            <x-forms.errors name="operator" />
                        </label>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="block">
                            <span class="sr-only">{{ __('Threshold') }}</span>
                            <input type="number" step="0.01" min="0" name="threshold" value="{{ old('threshold', 85) }}" class="input secondary w-full rounded-md" aria-label="{{ __('Threshold') }}">
                            <x-forms.errors name="threshold" />
                        </label>
                        <label class="block">
                            <span class="sr-only">{{ __('Consecutive breaches') }}</span>
                            <input type="number" min="1" max="10" name="consecutive_breaches" value="{{ old('consecutive_breaches', 3) }}" class="input secondary w-full rounded-md" aria-label="{{ __('Consecutive breaches') }}">
                            <x-forms.errors name="consecutive_breaches" />
                        </label>
                        <label class="block">
                            <span class="sr-only">{{ __('Cooldown') }}</span>
                            <select name="cooldown_minutes" class="input secondary w-full rounded-md" aria-label="{{ __('Cooldown') }}">
                                @foreach ([5 => '5m', 15 => '15m', 30 => '30m', 60 => '1h', 180 => '3h', 1440 => '24h'] as $minutes => $label)
                                    <option value="{{ $minutes }}" @selected((string) old('cooldown_minutes', 30) === (string) $minutes)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-forms.errors name="cooldown_minutes" />
                        </label>
                    </div>
                    <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Create alert') }}</x-ui.button>
                </form>
            </x-dialogs.modal>
        @endif
    </section>

    <details id="correlated-signals" class="ui-responsive-details group ui-card mt-6 scroll-mt-24 overflow-hidden" open data-responsive-details data-responsive-details-mobile-open="false" aria-labelledby="correlated-signals-title">
        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 sm:p-6 [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Incident command centre') }}</span>
                <span id="correlated-signals-title" class="mt-1 block text-xl font-black text-primary">{{ __('Recent deployment and health signals') }}</span>
                <span class="mt-1 block text-sm font-normal leading-6 text-secondary">{{ __('Use timestamps and resource links to correlate an incident before recording the review below.') }}</span>
            </span>
            <span class="shrink-0 text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="ui-responsive-details__content border-t border-primary p-5 sm:p-6 lg:border-0">
            <div class="grid gap-5 lg:grid-cols-2">
            <div>
                <h3 class="text-xs font-bold uppercase text-secondary">{{ __('Deployments') }}</h3>
                <div class="mt-2 space-y-2">
                    @forelse ($correlatedBuilds as $signal)
                        <a href="{{ route('builds.show', $signal) }}" class="flex items-center gap-3 rounded-lg border border-primary bg-secondary p-3 text-sm transition hover:border-ternary">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $signal->status === \App\Models\Build::STATUS_SUCCEEDED ? 'bg-green-500' : 'bg-red-500' }}" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 truncate font-bold text-primary">{{ $signal->repository->name }}</span>
                            <span class="shrink-0 text-xs text-secondary">{{ str($signal->status)->headline() }} · {{ $signal->finished_at?->diffForHumans() }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-secondary">{{ __('No recent terminal deployments.') }}</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h3 class="text-xs font-bold uppercase text-secondary">{{ __('Failed health checks') }}</h3>
                <div class="mt-2 space-y-2">
                    @forelse ($correlatedHealthChecks as $signal)
                        <a href="{{ route('websites.show', $signal->website) }}" class="flex items-center gap-3 rounded-lg border border-primary bg-secondary p-3 text-sm transition hover:border-ternary">
                            <span class="h-2 w-2 shrink-0 rounded-full bg-red-500" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 truncate font-bold text-primary">{{ $signal->website->name }}</span>
                            <span class="shrink-0 text-xs text-secondary">{{ $signal->status_code ?: __('Transport') }} · {{ $signal->checked_at?->diffForHumans() }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-secondary">{{ __('No retained health failures.') }}</p>
                    @endforelse
                </div>
            </div>
            </div>
        </div>
    </details>

    @if ($environmentProjects->isNotEmpty())
        <section class="ui-card mt-6 p-6" aria-labelledby="environment-evidence-heading">
            <details id="environment-evidence" class="rounded-xl" aria-labelledby="environment-evidence-heading">
                <summary class="flex cursor-pointer list-none items-start justify-between gap-3 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Investigation') }}</p>
                        <h2 id="environment-evidence-heading" class="mt-1 text-xl font-black text-primary">{{ __('Environment evidence') }}</h2>
                        <p class="mt-1 max-w-3xl text-sm text-secondary">{{ __('Connect deployments, health observations, runtime-log metadata and related incidents for a selected environment.') }}</p>
                    </div>
                    <span class="flex shrink-0 items-center gap-2">
                        <x-ui.badge>{{ trans_choice(':count environment|:count environments', $environmentProjects->sum(fn ($project) => $project->environments->count()), ['count' => $environmentProjects->sum(fn ($project) => $project->environments->count())]) }}</x-ui.badge>
                        <span class="text-secondary" aria-hidden="true">⌄</span>
                    </span>
                </summary>
                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($environmentProjects as $project)
                        @foreach ($project->environments as $environment)
                            <a href="{{ route('observability.environments.context', $environment) }}" class="ui-card ui-card--interactive block bg-secondary p-4">
                                <p class="text-xs font-bold uppercase tracking-widest text-secondary">{{ $project->name }}</p>
                                <div class="mt-1 flex items-center justify-between gap-3"><h3 class="truncate font-black text-primary">{{ $environment->name }}</h3><x-ui.badge>{{ str((string) $environment->type)->headline() }}</x-ui.badge></div>
                                <p class="mt-2 text-xs text-secondary">{{ $environment->branch }} · {{ str((string) $environment->status)->headline() }}</p>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            </details>
        </section>
    @endif

    <div class="mt-8 grid gap-6 xl:grid-cols-2">
        <section id="alert-destination-panel" class="ui-card scroll-mt-24 p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Integrations') }}</p>
            <h2 class="mt-1 text-xl font-black text-primary">{{ __('Alert destinations') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Send signed failure and recovery events to Slack or your HTTPS webhook.') }}</p>
            <details id="alert-destinations" class="mt-5 rounded-xl border border-primary bg-primary p-4" @if ($errors->any()) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md font-bold text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                    <span>{{ __('Manage destinations') }}</span>
                    <span class="flex items-center gap-2">
                        @if ($destinations->isNotEmpty())
                            <x-ui.badge>{{ $destinations->count() }}</x-ui.badge>
                        @endif
                        <span class="text-secondary" aria-hidden="true">⌄</span>
                    </span>
                </summary>
                <div class="mt-4 space-y-3">
                @forelse ($destinations as $destination)
                    <article class="rounded-xl border border-primary bg-secondary p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-primary">{{ $destination->name }}</p>
                                <p class="text-xs text-secondary">{{ ucfirst($destination->type) }} · {{ implode(', ', $destination->events ?? []) }} · {{ $destination->last_delivered_at?->diffForHumans() ?? __('never delivered') }}</p>
                                @if ($destination->last_error)<p class="ui-alert ui-alert--danger mt-2 text-xs">{{ $destination->last_error }}</p>@endif
                            </div>
                            @if ($canManage)
                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('observability.destinations.test', $destination) }}">@csrf<x-ui.button type="submit" variant="secondary">{{ __('Test') }}</x-ui.button></form>
                                    <form method="POST" action="{{ route('observability.destinations.destroy', $destination) }}">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button></form>
                                </div>
                            @endif
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state :title="__('No external alert destinations')" :description="__('Configure a destination to send signed failure and recovery events.')" icon="bell" />
                @endforelse
                </div>
            @if ($canManage)
                <form method="POST" action="{{ route('observability.destinations.store') }}" class="mt-5 grid gap-4 rounded-xl border border-primary bg-secondary p-4 sm:grid-cols-2">
                    @csrf
                    <h3 class="sm:col-span-2 font-bold text-primary">{{ __('Add alert destination') }}</h3>
                    <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Name') }}</span><input name="name" placeholder="Engineering alerts" class="input secondary w-full rounded-md" required></label>
                    <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Type') }}</span><select name="type" class="input secondary w-full rounded-md"><option value="email">Email</option><option value="discord">Discord</option><option value="teams">Microsoft Teams</option><option value="pagerduty">PagerDuty</option><option value="slack">Slack</option><option value="webhook">{{ __('Signed webhook') }}</option></select></label>
                    <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Endpoint') }}</span><input name="endpoint" placeholder="{{ __('Email, webhook URL, or PagerDuty routing key') }}" autocomplete="off" class="input secondary w-full rounded-md" required></label>
                    <fieldset class="flex flex-wrap gap-4 sm:col-span-2"><legend class="sr-only">{{ __('Events') }}</legend><label class="flex items-center gap-2"><input type="checkbox" name="events[]" value="failure" checked><span class="text-sm text-secondary">{{ __('Failures') }}</span></label><label class="flex items-center gap-2"><input type="checkbox" name="events[]" value="recovery" checked><span class="text-sm text-secondary">{{ __('Recoveries') }}</span></label></fieldset>
                    <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Add destination') }}</x-ui.button>
                </form>
            @endif
            </details>
        </section>

        <section id="status-page-panel" class="ui-card scroll-mt-24 p-6">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Customer communication') }}</p>
            <h2 class="mt-1 text-xl font-black text-primary">{{ __('Public status pages') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Publish live component health and rolling 30-day uptime without exposing infrastructure details.') }}</p>
            <details id="status-pages" class="mt-5 rounded-xl border border-primary bg-primary p-4" @if ($errors->any()) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md font-bold text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                    <span>{{ __('Manage status pages') }}</span>
                    <span class="flex items-center gap-2">
                        @if ($statusPages->isNotEmpty())
                            <x-ui.badge>{{ $statusPages->count() }}</x-ui.badge>
                        @endif
                        <span class="text-secondary" aria-hidden="true">⌄</span>
                    </span>
                </summary>
                <div class="mt-4 space-y-3">
                @forelse ($statusPages as $page)
                    <article class="rounded-xl border border-primary bg-secondary p-4">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('status.show', $page->slug) }}" target="_blank" rel="noopener noreferrer" class="font-bold text-primary hover:underline">{{ $page->name }}</a>
                                <p class="mt-1 text-xs text-secondary">/{{ $page->slug }} · {{ trans_choice(':count component|:count components', $page->websites->count(), ['count' => $page->websites->count()]) }}</p>
                            </div>
                            <x-ui.badge tone="{{ $page->is_published ? 'success' : 'neutral' }}">{{ $page->is_published ? __('Published') : __('Private') }}</x-ui.badge>
                            @if ($canManage)
                                <form method="POST" action="{{ route('observability.status-pages.destroy', $page) }}">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button></form>
                            @endif
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state :title="__('No status pages published')" :description="__('Publish a status page to communicate component health.')" icon="globe-alt" />
                @endforelse
                </div>
            @if ($canManage)
                <form method="POST" action="{{ route('observability.status-pages.store') }}" class="mt-5 space-y-4 rounded-xl border border-primary bg-secondary p-4">
                    @csrf
                    <h3 class="font-bold text-primary">{{ __('Create status page') }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Name') }}</span><input name="name" placeholder="BuildPusher Status" class="input secondary w-full rounded-md" required></label>
                        <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Slug') }}</span><input name="slug" placeholder="buildpusher" class="input secondary w-full rounded-md"></label>
                        <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Description') }}</span><textarea name="description" placeholder="Current platform availability" class="input secondary w-full rounded-md"></textarea></label>
                    </div>
                    <fieldset class="grid gap-2 sm:grid-cols-2"><legend class="mb-1 text-xs font-bold uppercase text-secondary">{{ __('Components') }}</legend>@foreach ($websites as $website)<label class="flex items-center gap-2 rounded-lg border border-primary p-3"><input type="checkbox" name="website_ids[]" value="{{ $website->id }}"><span class="min-w-0 truncate text-sm text-primary">{{ $website->name }}</span></label>@endforeach</fieldset>
                    <input type="hidden" name="is_published" value="1">
                    <x-ui.button type="submit" variant="primary">{{ __('Publish status page') }}</x-ui.button>
                </form>
            @endif
            </details>
        </section>
    </div>

    <section id="status-incident-timeline" class="ui-card mt-6 scroll-mt-24 p-6" aria-labelledby="status-incident-timeline-title">
        <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Communication timeline') }}</p>
        <h2 id="status-incident-timeline-title" class="mt-1 text-xl font-black text-primary">{{ __('Incidents and planned maintenance') }}</h2>
        <p class="mt-1 text-sm text-secondary">{{ __('Publish updates to a status page and notify its confirmed subscribers.') }}</p>

        <details id="status-incident-history" class="mt-5 rounded-xl border border-primary bg-primary p-4" @if ($errors->any() || $incidents->contains(fn ($incident) => ! in_array($incident->status, ['resolved', 'completed'], true))) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-md font-bold text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                <span>{{ __('Show status updates') }}</span>
                <span class="flex items-center gap-2">
                    @if ($incidents->isNotEmpty())
                        <x-ui.badge>{{ $incidents->count() }}</x-ui.badge>
                    @endif
                    <span class="text-secondary" aria-hidden="true">⌄</span>
                </span>
            </summary>
            <div class="mt-4 space-y-3">
            @forelse ($incidents as $incident)
                <article class="rounded-xl border border-primary bg-secondary p-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-bold text-primary">{{ $incident->title }}</h3>
                            <x-ui.badge tone="{{ in_array($incident->status, ['resolved', 'completed'], true) ? 'success' : ($incident->severity === 'critical' ? 'danger' : 'warning') }}">{{ str($incident->status)->headline() }}</x-ui.badge>
                        </div>
                        <p class="text-xs text-secondary">{{ $incident->statusPage->name }} · {{ str($incident->kind)->headline() }} · {{ str($incident->severity)->headline() }} · {{ $incident->starts_at->utc()->format('M j H:i').' UTC' }}</p>
                        <p class="mt-2 text-sm text-secondary">{{ $incident->message }}</p>
                        @if ($incident->root_cause || $incident->remediation || $incident->follow_up)
                            <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                @foreach ([[__('Root cause'), $incident->root_cause], [__('Remediation'), $incident->remediation], [__('Follow-up'), $incident->follow_up]] as [$label, $value])
                                    @if ($value)
                                        <div class="ui-card ui-card--muted p-3"><p class="text-xs font-bold uppercase text-secondary">{{ $label }}</p><p class="mt-1 text-sm text-primary">{{ $value }}</p></div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if ($canManage)
                        <details class="mt-4 rounded-lg border border-primary bg-primary p-3">
                            <summary class="cursor-pointer text-xs font-bold text-ternary">{{ __('Update or complete review') }}</summary>
                            <form method="POST" action="{{ route('observability.incidents.update', $incident) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="kind" value="{{ $incident->kind }}">
                                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Status') }}</span><select name="status" class="input secondary w-full rounded-md">@foreach (\App\Models\StatusIncident::STATUSES as $status)<option value="{{ $status }}" @selected($incident->status === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
                                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Severity') }}</span><select name="severity" class="input secondary w-full rounded-md">@foreach (\App\Models\StatusIncident::SEVERITIES as $severity)<option value="{{ $severity }}" @selected($incident->severity === $severity)>{{ ucfirst($severity) }}</option>@endforeach</select></label>
                                <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Title') }}</span><input name="title" value="{{ $incident->title }}" required class="input secondary w-full rounded-md"></label>
                                <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Message') }}</span><textarea name="message" required class="input secondary w-full rounded-md">{{ $incident->message }}</textarea></label>
                                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Root cause') }}</span><textarea name="root_cause" maxlength="5000" class="input secondary w-full rounded-md" placeholder="{{ __('Root cause (internal review)') }}">{{ $incident->root_cause }}</textarea></label>
                                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Remediation') }}</span><textarea name="remediation" maxlength="5000" class="input secondary w-full rounded-md" placeholder="{{ __('Remediation taken') }}">{{ $incident->remediation }}</textarea></label>
                                <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Follow-up') }}</span><textarea name="follow_up" maxlength="5000" class="input secondary w-full rounded-md" placeholder="{{ __('Follow-up actions and owners') }}">{{ $incident->follow_up }}</textarea></label>
                                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Starts') }}</span><input type="datetime-local" name="starts_at" value="{{ $incident->starts_at->format('Y-m-d\TH:i') }}" class="input secondary w-full rounded-md"></label>
                                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Ends') }}</span><input type="datetime-local" name="ends_at" value="{{ $incident->ends_at?->format('Y-m-d\TH:i') }}" class="input secondary w-full rounded-md"></label>
                                <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Publish update') }}</x-ui.button>
                            </form>
                        </details>
                    @endif
                </article>
            @empty
                <x-ui.empty-state :title="__('No incidents or maintenance events')" :description="__('Published incidents and maintenance updates will appear here.')" icon="warning" />
            @endforelse
            </div>

        @if ($canManage && $statusPages->isNotEmpty())
            <form method="POST" action="{{ route('observability.incidents.store') }}" class="mt-5 grid gap-4 rounded-xl border border-primary bg-secondary p-4 sm:grid-cols-2">
                @csrf
                <h3 class="sm:col-span-2 font-bold text-primary">{{ __('Publish a status update') }}</h3>
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Status page') }}</span><select name="status_page_id" class="input secondary w-full rounded-md" required>@foreach ($statusPages as $page)<option value="{{ $page->id }}">{{ $page->name }}</option>@endforeach</select></label>
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Kind') }}</span><select name="kind" class="input secondary w-full rounded-md"><option value="incident">{{ __('Incident') }}</option><option value="maintenance">{{ __('Planned maintenance') }}</option></select></label>
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Status') }}</span><select name="status" class="input secondary w-full rounded-md"><option value="investigating">{{ __('Investigating') }}</option><option value="identified">{{ __('Identified') }}</option><option value="monitoring">{{ __('Monitoring') }}</option><option value="resolved">{{ __('Resolved') }}</option><option value="scheduled">{{ __('Scheduled') }}</option><option value="in_progress">{{ __('In progress') }}</option><option value="completed">{{ __('Completed') }}</option></select></label>
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Severity') }}</span><select name="severity" class="input secondary w-full rounded-md"><option value="minor">{{ __('Minor') }}</option><option value="major">{{ __('Major') }}</option><option value="critical">{{ __('Critical') }}</option></select></label>
                <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Title') }}</span><input name="title" placeholder="{{ __('API latency') }}" required class="input secondary w-full rounded-md"></label>
                <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Message') }}</span><textarea name="message" placeholder="{{ __('What users should know') }}" required class="input secondary w-full rounded-md"></textarea></label>
                <input type="hidden" name="root_cause" value="">
                <input type="hidden" name="remediation" value="">
                <input type="hidden" name="follow_up" value="">
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Starts') }}</span><input type="datetime-local" name="starts_at" value="{{ now()->format('Y-m-d\TH:i') }}" required class="input secondary w-full rounded-md"></label>
                <label class="block"><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Ends (maintenance)') }}</span><input type="datetime-local" name="ends_at" class="input secondary w-full rounded-md"></label>
                <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Publish status update') }}</x-ui.button>
            </form>
        @endif
        </details>
    </section>
</x-layouts.app>
