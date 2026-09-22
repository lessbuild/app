<x-layouts.app>
    @php
        $environment = $context->environment;
        $website = $environment->website;
        $server = $environment->server;
        $contextFiltersAreActive = $context->window !== '24h'
            || $context->serviceId !== null
            || $context->deployment !== 'all'
            || $context->severity !== 'all';
        $investigationDialogOpen = request()->query('dialog') === 'save-investigation'
            || (old('_investigation_view_form') === '1' && $errors->any());
        $investigationDialogUrl = route('observability.environments.context', [
            'environment' => $environment,
            'window' => $context->window,
            'service' => $context->serviceId ?? 'all',
            'deployment' => $context->deployment,
            'severity' => $context->severity,
            'dialog' => 'save-investigation',
        ]);
        $healthChecksDialogId = 'environment-health-checks-dialog';
        $healthChecksDialogOpen = request()->query('dialog') === $healthChecksDialogId;
        $healthChecksDialogUrl = (string) \Illuminate\Support\Uri::of($shareUrl)->withQuery(['dialog' => $healthChecksDialogId]);
        $healthChecksContentUrl = $website ? route('websites.health-checks.index', [
            'website' => $website,
            'fragment' => 'website-health-checks',
        ]) : null;
    @endphp

    <x-layouts.partials.breadcrumbs
        :route="route('projects.show', $environment->project)"
        :title="__('Back to application')"
    />

    <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
        <x-layouts.partials.heading
            icon="chip"
            :title="__('Environment evidence')"
            :description="__('A bounded view of deployment, health, runtime-log and incident signals for :environment.', ['environment' => $environment->name])"
        />
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="$shareUrl" variant="secondary" data-testid="share-environment-context">{{ __('Shareable link') }}</x-ui.button>
            <x-ui.button
                :href="$investigationDialogUrl"
                data-modal-trigger="save-investigation-dialog"
                aria-controls="save-investigation-dialog"
                aria-expanded="{{ $investigationDialogOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('Save investigation') }}
            </x-ui.button>
            <x-ui.button :href="route('observability.index')" variant="secondary">{{ __('Observability overview') }}</x-ui.button>
        </div>
    </div>

    <x-ui.insights
        id="environment-context-insights"
        class="mt-6"
        :summary="trans_choice(':count deployment in evidence|:count deployments in evidence', $context->builds->count(), ['count' => $context->builds->count()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.stat
                :label="__('Deployments')"
                :value="$context->builds->count()"
                :description="__('Bounded deployment records in the selected window.')"
            />
            <x-ui.stat
                :label="__('Health checks')"
                :value="$context->healthChecks->count()"
                :description="__('Website observations retained for this context.')"
            />
            <x-ui.stat
                :label="__('Log snapshots')"
                :value="$context->runtimeLogs->count()"
                :description="__('Metadata records; log bodies stay out of this view.')"
            />
            <x-ui.stat
                :label="__('Incidents')"
                :value="$context->incidents->count()"
                :description="__('Explicitly related operational incidents.')"
            />
            <x-ui.stat
                :label="__('Services')"
                :value="$context->services->count()"
                :description="__('Authorized deployment services for this website.')"
            />
        </dl>
    </x-ui.insights>

    <x-ui.local-nav class="mt-5" :label="__('Environment evidence sections')">
        <a href="#environment-context-filters" class="ui-local-nav__link">{{ __('Filters') }}</a>
        <a href="#context-deployments" class="ui-local-nav__link">{{ __('Deployments') }}</a>
        <a href="#context-health" class="ui-local-nav__link">{{ __('Health') }}</a>
        <a href="#context-logs" class="ui-local-nav__link">{{ __('Logs') }}</a>
        <a href="#context-incidents" class="ui-local-nav__link">{{ __('Incidents') }}</a>
    </x-ui.local-nav>

    <section class="ui-panel mt-8 p-5 sm:p-6" aria-labelledby="environment-context-heading" data-observability-context-card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ $environment->project->name }}</p>
                <h2 id="environment-context-heading" class="mt-1 text-2xl font-extrabold text-ink">{{ $environment->name }}</h2>
                <p class="mt-1 text-sm text-muted">{{ $environment->branch }} · {{ ucfirst((string) ($environment->runtime_type ?: 'php')) }} · {{ str((string) $environment->type)->headline() }}</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <x-ui.badge tone="{{ in_array((string) $environment->status, ['active', 'running', 'ready'], true) ? 'success' : 'accent' }}">{{ str((string) $environment->status)->headline() }}</x-ui.badge>
                @if($environment->is_protected)
                    <x-ui.badge tone="warning">{{ __('Protected') }}</x-ui.badge>
                @endif
            </div>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div class="rounded-card border border-line bg-surface-muted p-4">
                <p class="ui-eyebrow">{{ __('Website') }}</p>
                <p class="mt-1 font-bold text-ink">{{ $website?->name ?? __('Not attached') }}</p>
                <p class="mt-1 text-xs text-muted">{{ $website ? str((string) $website->health_status)->headline() : __('No website evidence available') }}</p>
            </div>
            <div class="rounded-card border border-line bg-surface-muted p-4">
                <p class="ui-eyebrow">{{ __('Server') }}</p>
                <p class="mt-1 font-bold text-ink">{{ $server?->label ?? __('Not attached') }}</p>
                <p class="mt-1 text-xs text-muted">{{ $server ? str((string) $server->provisioning_status)->headline() : __('No server evidence available') }}</p>
            </div>
            <div class="rounded-card border border-line bg-surface-muted p-4">
                <p class="ui-eyebrow">{{ __('Evidence window') }}</p>
                <p class="mt-1 font-bold text-ink">{{ $context->window }}</p>
                <p class="mt-1 text-xs text-muted">{{ __('Since :time', ['time' => $context->since->utc()->format('M j Y H:i').' UTC']) }}</p>
            </div>
        </div>

        <details id="environment-context-filters" class="ui-panel mt-5 bg-surface-muted p-4" @if ($contextFiltersAreActive || $errors->any()) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-control font-bold text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                <span>{{ __('Adjust evidence filters') }}</span>
                <span class="flex items-center gap-2">
                    @if ($contextFiltersAreActive)
                        <x-ui.badge tone="accent">{{ __('Filtered') }}</x-ui.badge>
                    @endif
                    <span class="text-muted" aria-hidden="true">⌄</span>
                </span>
            </summary>
            <form method="GET" action="{{ route('observability.environments.context', $environment) }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label>
                    <span class="ui-label">{{ __('Evidence window') }}</span>
                    <select name="window" class="ui-input">
                        @foreach(\App\Data\ObservabilityContextFilters::WINDOWS as $window => $hours)
                            <option value="{{ $window }}" @selected($context->window === $window)>{{ $window }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="ui-label">{{ __('Service') }}</span>
                    <select name="service" class="ui-input">
                        <option value="all" @selected($context->serviceId === null)>{{ __('All services') }}</option>
                        @foreach($context->services as $service)
                            <option value="{{ $service->id }}" @selected($context->serviceId === (int) $service->id)>{{ $service->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="ui-label">{{ __('Deployments') }}</span>
                    <select name="deployment" class="ui-input">
                        @foreach(['all' => __('All deployments'), 'active' => __('Active'), 'successful' => __('Successful'), 'unsuccessful' => __('Unsuccessful')] as $deployment => $label)
                            <option value="{{ $deployment }}" @selected($context->deployment === $deployment)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="ui-label">{{ __('Incident severity') }}</span>
                    <select name="severity" class="ui-input">
                        @foreach(\App\Data\ObservabilityContextFilters::SEVERITIES as $severity)
                            <option value="{{ $severity }}" @selected($context->severity === $severity)>{{ str($severity)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <x-ui.button type="submit" variant="primary" class="sm:col-span-2 lg:col-span-4">{{ __('Refresh context') }}</x-ui.button>
            </form>
            <p class="mt-3 text-xs text-muted">{{ __('Service filtering narrows deployment evidence to one repository target; health, runtime and shared infrastructure signals remain visible. Active deployments and unresolved incidents remain visible even when they began before this window. Adjacent signals are evidence to investigate, not proof of causation.') }}</p>
        </details>

        <details id="save-investigation-view" class="ui-panel mt-5 bg-surface-muted p-4">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-control font-bold text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                <span>{{ __('Saved investigation views') }}</span>
                <span class="flex items-center gap-2">
                    @if ($savedInvestigations->isNotEmpty())
                        <x-ui.badge>{{ $savedInvestigations->count() }}</x-ui.badge>
                    @endif
                    <span class="text-muted" aria-hidden="true">⌄</span>
                </span>
            </summary>
            @if($savedInvestigations->isNotEmpty())
                <div class="mt-5 border-t border-line pt-5" data-testid="saved-investigations">
                    <h3 class="font-bold text-ink">{{ __('Saved investigations for this environment') }}</h3>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach($savedInvestigations as $saved)
                            <div class="flex items-center gap-3 rounded-card border border-line bg-surface p-3">
                                <a href="{{ route('observability.investigations.show', $saved) }}" class="min-w-0 flex-1">
                                    <span class="block truncate font-bold text-ink">{{ $saved->name }}</span>
                                    <span class="mt-0.5 block text-xs text-muted">{{ __('By :name · expires :time', ['name' => $saved->creator?->name ?? __('former member'), 'time' => $saved->expires_at?->diffForHumans()]) }}</span>
                                </a>
                                @if($canManageInvestigationViews || (int) $saved->created_by === (int) auth()->id())
                                    <form method="POST" action="{{ route('observability.investigations.destroy', $saved) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger" aria-label="{{ __('Remove investigation :name', ['name' => $saved->name]) }}">{{ __('Remove') }}</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="mt-4 text-sm text-muted">{{ __('No saved investigation views for this environment yet.') }}</p>
            @endif
        </details>

        <x-scenes.observability.investigation-dialog
            :environment="$environment"
            :context="$context"
            :open="$investigationDialogOpen"
        />
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section id="context-deployments" class="ui-panel p-5 sm:p-6" aria-labelledby="context-deployments-heading" data-observability-context-section>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Deployment evidence') }}</p>
                    <h2 id="context-deployments-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Recent environment deployments') }}</h2>
                </div>
                <span class="text-xs text-muted">{{ trans_choice(':count result|:count results', $context->builds->count(), ['count' => $context->builds->count()]) }}</span>
            </div>
            <div class="ui-inventory-list mt-4 space-y-2">
                @forelse($context->builds as $build)
                    @php
                        $buildColor = in_array($build->status, [\App\Models\Build::STATUS_FAILED, \App\Models\Build::STATUS_CANCELED], true)
                            ? 'var(--ui-danger)'
                            : ($build->status === \App\Models\Build::STATUS_SUCCEEDED ? 'var(--ui-success)' : 'var(--ui-warning)');
                        $observation = $context->deploymentObservations->get((int) $build->id);
                        $observationTone = match ($observation?->status) {
                            \App\Models\DeploymentObservation::STATUS_HEALTHY => 'success',
                            \App\Models\DeploymentObservation::STATUS_FAILED, \App\Models\DeploymentObservation::STATUS_EXPIRED => 'danger',
                            \App\Models\DeploymentObservation::STATUS_PENDING, \App\Models\DeploymentObservation::STATUS_OBSERVING => 'warning',
                            default => 'neutral',
                        };
                    @endphp
                    <a href="{{ route('builds.show', $build) }}" class="flex items-center gap-3 rounded-card border border-line bg-surface-muted p-3 transition hover:border-line" data-observability-context-deployment>
                        <span class="ui-status-dot ui-status-dot-lg" style="--ui-status-dot: {{ $buildColor }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-bold text-ink">{{ $build->repository?->name ?? __('Deployment') }}</span>
                            <span class="mt-0.5 block truncate font-mono text-xs text-muted">{{ $build->shortRevision() ?? __('Revision pending') }} · {{ str((string) $build->trigger_source)->headline() }}</span>
                        </span>
                        <span class="shrink-0 text-right text-xs text-muted">{{ str((string) $build->status)->headline() }}<br>{{ $build->created_at?->diffForHumans() }}</span>
                    </a>
                    @if($observation)
                        <div class="-mt-1 rounded-b-xl border border-t-0 border-line bg-surface-muted px-3 pb-3 pt-2 text-xs" data-testid="deployment-observation-evidence-{{ $build->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-ink">{{ __('Post-deployment verification') }}</span>
                                <x-ui.badge :tone="$observationTone">{{ str($observation->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-1 text-muted">
                                {{ __('Revision-linked · :count successful checks · :duration-minute window', ['count' => $observation->successfulChecks, 'duration' => $observation->durationMinutes]) }}
                                @if($observation->lastHttpStatus !== null)
                                    · {{ __('HTTP :status', ['status' => $observation->lastHttpStatus]) }}
                                @endif
                                @if($observation->lastCheckedAt)
                                    · {{ __('Checked :time', ['time' => $observation->lastCheckedAt->diffForHumans()]) }}
                                @endif
                            </p>
                        </div>
                    @endif
                @empty
                    <p class="rounded-card border border-dashed border-line p-4 text-sm text-muted">{{ __('No deployment metadata was recorded in this window.') }}</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-muted">{{ __('Open a deployment for its exact revision, plan-driven timeline, bounded log and failure guidance.') }}</p>
        </section>

        <section id="context-health" class="ui-panel p-5 sm:p-6" aria-labelledby="context-health-heading" data-observability-context-section>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Health evidence') }}</p>
                    <h2 id="context-health-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Website observations') }}</h2>
                </div>
                @if($website)
                    <x-ui.button
                        :href="route('websites.health-checks.index', $website)"
                        data-modal-trigger="{{ $healthChecksDialogId }}"
                        data-modal-content-url="{{ $healthChecksContentUrl }}"
                        data-modal-history-url="{{ $healthChecksDialogUrl }}"
                        aria-controls="{{ $healthChecksDialogId }}"
                        aria-expanded="{{ $healthChecksDialogOpen ? 'true' : 'false' }}"
                        variant="ghost"
                        class="text-xs"
                    >{{ __('View health history') }}</x-ui.button>
                @endif
            </div>
            <div class="ui-inventory-list mt-4 space-y-2">
                @forelse($context->healthChecks as $check)
                    <div class="flex items-center gap-3 rounded-card border border-line bg-surface-muted p-3" data-observability-context-health>
                        <span class="ui-status-dot ui-status-dot-lg" style="--ui-status-dot: {{ $check->successful ? 'var(--ui-success)' : 'var(--ui-danger)' }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold text-ink">{{ $check->successful ? __('Healthy response') : __('Failed response') }}</span>
                            <span class="mt-0.5 block text-xs text-muted">{{ str((string) $check->source)->headline() }} · {{ $check->http_status ?: __('Transport failure') }} · {{ $check->duration_ms !== null ? $check->duration_ms.' ms' : __('No duration') }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-muted">{{ $check->checked_at?->diffForHumans() }}</span>
                    </div>
                @empty
                    <p class="rounded-card border border-dashed border-line p-4 text-sm text-muted">{{ $website ? __('No health observations were recorded in this window.') : __('Attach a website to collect health evidence.') }}</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-muted">{{ __('Health history is retained separately and does not represent an SLA calculation.') }}</p>
        </section>

        <section id="context-logs" class="ui-panel p-5 sm:p-6" aria-labelledby="context-logs-heading" data-observability-context-section>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Runtime evidence') }}</p>
                    <h2 id="context-logs-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Log snapshots') }}</h2>
                </div>
                <span class="text-xs text-muted">{{ __('Metadata only') }}</span>
            </div>
            <div class="mt-4 space-y-2">
                @if($website)
                    @foreach(\App\Models\WebsiteLogSnapshot::TYPES as $type)
                        @php($snapshot = $context->runtimeLogs->firstWhere('type', $type))
                    <a href="{{ route('websites.runtime-logs.show', [$website, $type]) }}" class="flex items-center gap-3 rounded-card border border-line bg-surface-muted p-3 transition hover:border-line" data-observability-context-log>
                            <span class="min-w-0 flex-1">
                                <span class="block font-bold text-ink">{{ str($type)->headline() }} {{ __('log') }}</span>
                                <span class="mt-0.5 block text-xs text-muted">{{ str((string) ($snapshot?->status ?? 'idle'))->headline() }} · {{ $snapshot?->refreshed_at ? __('Updated :time', ['time' => $snapshot->refreshed_at->diffForHumans()]) : __('Not collected yet') }}</span>
                            </span>
                            <span class="ui-link text-xs">{{ __('Open') }}</span>
                        </a>
                    @endforeach
                @else
                    <p class="rounded-card border border-dashed border-line p-4 text-sm text-muted">{{ __('Attach a website to inspect runtime logs.') }}</p>
                @endif
            </div>
            <p class="mt-4 text-xs text-muted">{{ __('The context never loads log bodies. The existing website route rechecks authorization and applies no-store response headers.') }}</p>
        </section>

        <section id="context-incidents" class="ui-panel p-5 sm:p-6" aria-labelledby="context-incidents-heading" data-observability-context-section>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Response evidence') }}</p>
                    <h2 id="context-incidents-heading" class="mt-1 text-xl font-extrabold text-ink">{{ __('Related operational incidents') }}</h2>
                </div>
                <a href="{{ route('observability.index') }}#operational-incidents" class="ui-link text-xs">{{ __('Open incident centre') }}</a>
            </div>
            <div class="mt-4 space-y-2">
                @forelse($context->incidents as $incident)
                    @php($incidentBuild = $incident->category === 'deployment' ? $context->builds->firstWhere('id', (int) $incident->resource_id) : null)
                    <div class="rounded-card border border-line bg-surface-muted p-3 transition hover:border-line" data-observability-context-incident>
                        <a href="{{ route('observability.index') }}#operational-incidents" class="block">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge tone="{{ $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED ? 'success' : ($incident->severity === 'critical' ? 'danger' : 'warning') }}">{{ str((string) $incident->status)->headline() }}</x-ui.badge>
                                <span class="text-xs text-muted">{{ str((string) $incident->severity)->headline() }} · {{ str((string) $incident->category)->headline() }} #{{ $incident->resource_id }}</span>
                            </div>
                            <p class="mt-2 font-bold text-ink">{{ $incident->title }}</p>
                            <p class="mt-1 text-xs text-muted">{{ trans_choice(':count occurrence|:count occurrences', $incident->occurrences, ['count' => $incident->occurrences]) }} · {{ __('Last seen :time', ['time' => $incident->last_seen_at?->diffForHumans()]) }} · {{ __('Owner: :owner', ['owner' => $incident->assignee?->name ?? __('Unassigned')]) }}</p>
                        </a>
                        @if($incidentBuild)
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line pt-3">
                                <a href="{{ route('builds.show', $incidentBuild) }}" class="ui-link text-xs" data-testid="incident-deployment-evidence-link">{{ __('Open deployment evidence') }}</a>
                                <span class="text-xs text-muted">{{ __('Recorded configuration identity is shown there when available.') }}</span>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="rounded-card border border-dashed border-line p-4 text-sm text-muted">{{ __('No explicitly related incidents were recorded in this window.') }}</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-muted">{{ __('Only concrete category/resource relationships are shown. Incident titles and status are context; the incident centre contains the authorized response timeline.') }}</p>
        </section>
    </div>

    @if($website)
        <x-dialogs.modal
            id="{{ $healthChecksDialogId }}"
            :title="__('Health check history')"
            :description="__('Review retained website observations without leaving this evidence context.')"
            :open="$healthChecksDialogOpen"
            body-class="p-0"
        >
            <div data-modal-content>
                <p class="p-5 text-sm text-muted">{{ __('Loading health history…') }}</p>
            </div>
        </x-dialogs.modal>
    @endif
</x-layouts.app>
