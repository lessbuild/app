<x-layouts.app>
    @php
        $environment = $context->environment;
        $website = $environment->website;
        $server = $environment->server;
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
            <a href="{{ $shareUrl }}" class="button secondary" data-testid="share-environment-context">{{ __('Shareable link') }}</a>
            <a href="{{ route('observability.index') }}" class="button secondary">{{ __('Observability overview') }}</a>
        </div>
    </div>

    <section class="mt-8 rounded-2xl border border-primary bg-primary p-6" aria-labelledby="environment-context-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ $environment->project->name }}</p>
                <h2 id="environment-context-heading" class="mt-1 text-2xl font-black text-primary">{{ $environment->name }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ $environment->branch }} · {{ ucfirst((string) ($environment->runtime_type ?: 'php')) }} · {{ str((string) $environment->type)->headline() }}</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-secondary px-3 py-1.5 font-bold text-secondary">{{ str((string) $environment->status)->headline() }}</span>
                @if($environment->is_protected)
                    <span class="rounded-full bg-ternary px-3 py-1.5 font-bold text-white">{{ __('Protected') }}</span>
                @endif
            </div>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-primary bg-secondary p-4">
                <p class="text-xs font-bold uppercase text-secondary">{{ __('Website') }}</p>
                <p class="mt-1 font-bold text-primary">{{ $website?->name ?? __('Not attached') }}</p>
                <p class="mt-1 text-xs text-secondary">{{ $website ? str((string) $website->health_status)->headline() : __('No website evidence available') }}</p>
            </div>
            <div class="rounded-xl border border-primary bg-secondary p-4">
                <p class="text-xs font-bold uppercase text-secondary">{{ __('Server') }}</p>
                <p class="mt-1 font-bold text-primary">{{ $server?->label ?? __('Not attached') }}</p>
                <p class="mt-1 text-xs text-secondary">{{ $server ? str((string) $server->provisioning_status)->headline() : __('No server evidence available') }}</p>
            </div>
            <div class="rounded-xl border border-primary bg-secondary p-4">
                <p class="text-xs font-bold uppercase text-secondary">{{ __('Evidence window') }}</p>
                <p class="mt-1 font-bold text-primary">{{ $context->window }}</p>
                <p class="mt-1 text-xs text-secondary">{{ __('Since :time', ['time' => $context->since->utc()->format('M j Y H:i').' UTC']) }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('observability.environments.context', $environment) }}" class="mt-5 grid gap-3 border-t border-primary pt-5 sm:grid-cols-2 lg:grid-cols-4">
            <label>
                <span class="block text-xs font-bold uppercase text-secondary">{{ __('Evidence window') }}</span>
                <select name="window" class="input secondary mt-1 rounded-sm">
                    @foreach(\App\Data\ObservabilityContextFilters::WINDOWS as $window => $hours)
                        <option value="{{ $window }}" @selected($context->window === $window)>{{ $window }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs font-bold uppercase text-secondary">{{ __('Service') }}</span>
                <select name="service" class="input secondary mt-1 rounded-sm">
                    <option value="all" @selected($context->serviceId === null)>{{ __('All services') }}</option>
                    @foreach($context->services as $service)
                        <option value="{{ $service->id }}" @selected($context->serviceId === (int) $service->id)>{{ $service->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs font-bold uppercase text-secondary">{{ __('Deployments') }}</span>
                <select name="deployment" class="input secondary mt-1 rounded-sm">
                    @foreach(['all' => __('All deployments'), 'active' => __('Active'), 'successful' => __('Successful'), 'unsuccessful' => __('Unsuccessful')] as $deployment => $label)
                        <option value="{{ $deployment }}" @selected($context->deployment === $deployment)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs font-bold uppercase text-secondary">{{ __('Incident severity') }}</span>
                <select name="severity" class="input secondary mt-1 rounded-sm">
                    @foreach(\App\Data\ObservabilityContextFilters::SEVERITIES as $severity)
                        <option value="{{ $severity }}" @selected($context->severity === $severity)>{{ str($severity)->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="button primary sm:col-span-2 lg:col-span-4">{{ __('Refresh context') }}</button>
        </form>
        <p class="mt-3 text-xs text-secondary">{{ __('Service filtering narrows deployment evidence to one repository target; health, runtime and shared infrastructure signals remain visible. Active deployments and unresolved incidents remain visible even when they began before this window. Adjacent signals are evidence to investigate, not proof of causation.') }}</p>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-2xl border border-primary bg-primary p-6" aria-labelledby="context-deployments-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Deployment evidence') }}</p>
                    <h2 id="context-deployments-heading" class="mt-1 text-xl font-black text-primary">{{ __('Recent environment deployments') }}</h2>
                </div>
                <span class="text-xs text-secondary">{{ trans_choice(':count result|:count results', $context->builds->count(), ['count' => $context->builds->count()]) }}</span>
            </div>
            <div class="mt-4 space-y-2">
                @forelse($context->builds as $build)
                    @php
                        $buildColor = in_array($build->status, [\App\Models\Build::STATUS_FAILED, \App\Models\Build::STATUS_CANCELED], true)
                            ? 'bg-red-500'
                            : ($build->status === \App\Models\Build::STATUS_SUCCEEDED ? 'bg-green-500' : 'bg-amber-500');
                        $observation = $context->deploymentObservations->get((int) $build->id);
                        $observationColor = match ($observation?->status) {
                            \App\Models\DeploymentObservation::STATUS_HEALTHY => 'bg-green-100 text-green-800',
                            \App\Models\DeploymentObservation::STATUS_FAILED, \App\Models\DeploymentObservation::STATUS_EXPIRED => 'bg-red-100 text-red-800',
                            \App\Models\DeploymentObservation::STATUS_SUPERSEDED => 'bg-secondary text-secondary',
                            \App\Models\DeploymentObservation::STATUS_PENDING, \App\Models\DeploymentObservation::STATUS_OBSERVING => 'bg-amber-100 text-amber-800',
                            default => 'bg-secondary text-secondary',
                        };
                    @endphp
                    <a href="{{ route('builds.show', $build) }}" class="flex items-center gap-3 rounded-xl border border-primary bg-secondary p-3 transition hover:border-ternary">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $buildColor }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-bold text-primary">{{ $build->repository?->name ?? __('Deployment') }}</span>
                            <span class="mt-0.5 block truncate font-mono text-xs text-secondary">{{ $build->shortRevision() ?? __('Revision pending') }} · {{ str((string) $build->trigger_source)->headline() }}</span>
                        </span>
                        <span class="shrink-0 text-right text-xs text-secondary">{{ str((string) $build->status)->headline() }}<br>{{ $build->created_at?->diffForHumans() }}</span>
                    </a>
                    @if($observation)
                        <div class="-mt-1 rounded-b-xl border border-t-0 border-primary bg-secondary px-3 pb-3 pt-2 text-xs" data-testid="deployment-observation-evidence-{{ $build->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-primary">{{ __('Post-deployment verification') }}</span>
                                <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase {{ $observationColor }}">{{ str($observation->status)->headline() }}</span>
                            </div>
                            <p class="mt-1 text-secondary">
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
                    <p class="rounded-xl border border-dashed border-primary p-4 text-sm text-secondary">{{ __('No deployment metadata was recorded in this window.') }}</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-secondary">{{ __('Open a deployment for its exact revision, plan-driven timeline, bounded log and failure guidance.') }}</p>
        </section>

        <section class="rounded-2xl border border-primary bg-primary p-6" aria-labelledby="context-health-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Health evidence') }}</p>
                    <h2 id="context-health-heading" class="mt-1 text-xl font-black text-primary">{{ __('Website observations') }}</h2>
                </div>
                @if($website)
                    <a href="{{ route('websites.health-checks.index', $website) }}" class="text-xs font-bold text-ternary underline">{{ __('View health history') }}</a>
                @endif
            </div>
            <div class="mt-4 space-y-2">
                @forelse($context->healthChecks as $check)
                    <div class="flex items-center gap-3 rounded-xl border border-primary bg-secondary p-3">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $check->successful ? 'bg-green-500' : 'bg-red-500' }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold text-primary">{{ $check->successful ? __('Healthy response') : __('Failed response') }}</span>
                            <span class="mt-0.5 block text-xs text-secondary">{{ str((string) $check->source)->headline() }} · {{ $check->http_status ?: __('Transport failure') }} · {{ $check->duration_ms !== null ? $check->duration_ms.' ms' : __('No duration') }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-secondary">{{ $check->checked_at?->diffForHumans() }}</span>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-primary p-4 text-sm text-secondary">{{ $website ? __('No health observations were recorded in this window.') : __('Attach a website to collect health evidence.') }}</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-secondary">{{ __('Health history is retained separately and does not represent an SLA calculation.') }}</p>
        </section>

        <section class="rounded-2xl border border-primary bg-primary p-6" aria-labelledby="context-logs-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Runtime evidence') }}</p>
                    <h2 id="context-logs-heading" class="mt-1 text-xl font-black text-primary">{{ __('Log snapshots') }}</h2>
                </div>
                <span class="text-xs text-secondary">{{ __('Metadata only') }}</span>
            </div>
            <div class="mt-4 space-y-2">
                @if($website)
                    @foreach(\App\Models\WebsiteLogSnapshot::TYPES as $type)
                        @php($snapshot = $context->runtimeLogs->firstWhere('type', $type))
                        <a href="{{ route('websites.runtime-logs.show', [$website, $type]) }}" class="flex items-center gap-3 rounded-xl border border-primary bg-secondary p-3 transition hover:border-ternary">
                            <span class="min-w-0 flex-1">
                                <span class="block font-bold text-primary">{{ str($type)->headline() }} {{ __('log') }}</span>
                                <span class="mt-0.5 block text-xs text-secondary">{{ str((string) ($snapshot?->status ?? 'idle'))->headline() }} · {{ $snapshot?->refreshed_at ? __('Updated :time', ['time' => $snapshot->refreshed_at->diffForHumans()]) : __('Not collected yet') }}</span>
                            </span>
                            <span class="text-xs font-bold text-ternary">{{ __('Open') }}</span>
                        </a>
                    @endforeach
                @else
                    <p class="rounded-xl border border-dashed border-primary p-4 text-sm text-secondary">{{ __('Attach a website to inspect runtime logs.') }}</p>
                @endif
            </div>
            <p class="mt-4 text-xs text-secondary">{{ __('The context never loads log bodies. The existing website route rechecks authorization and applies no-store response headers.') }}</p>
        </section>

        <section class="rounded-2xl border border-primary bg-primary p-6" aria-labelledby="context-incidents-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Response evidence') }}</p>
                    <h2 id="context-incidents-heading" class="mt-1 text-xl font-black text-primary">{{ __('Related operational incidents') }}</h2>
                </div>
                <a href="{{ route('observability.index') }}#operational-incidents" class="text-xs font-bold text-ternary underline">{{ __('Open incident centre') }}</a>
            </div>
            <div class="mt-4 space-y-2">
                @forelse($context->incidents as $incident)
                    @php($incidentBuild = $incident->category === 'deployment' ? $context->builds->firstWhere('id', (int) $incident->resource_id) : null)
                    <div class="rounded-xl border border-primary bg-secondary p-3 transition hover:border-ternary">
                        <a href="{{ route('observability.index') }}#operational-incidents" class="block">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase {{ $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED ? 'bg-green-100 text-green-800' : ($incident->severity === 'critical' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800') }}">{{ str((string) $incident->status)->headline() }}</span>
                                <span class="text-xs text-secondary">{{ str((string) $incident->severity)->headline() }} · {{ str((string) $incident->category)->headline() }} #{{ $incident->resource_id }}</span>
                            </div>
                            <p class="mt-2 font-bold text-primary">{{ $incident->title }}</p>
                            <p class="mt-1 text-xs text-secondary">{{ trans_choice(':count occurrence|:count occurrences', $incident->occurrences, ['count' => $incident->occurrences]) }} · {{ __('Last seen :time', ['time' => $incident->last_seen_at?->diffForHumans()]) }} · {{ __('Owner: :owner', ['owner' => $incident->assignee?->name ?? __('Unassigned')]) }}</p>
                        </a>
                        @if($incidentBuild)
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-primary pt-3">
                                <a href="{{ route('builds.show', $incidentBuild) }}" class="text-xs font-bold text-ternary underline" data-testid="incident-deployment-evidence-link">{{ __('Open deployment evidence') }}</a>
                                <span class="text-xs text-secondary">{{ __('Recorded configuration identity is shown there when available.') }}</span>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-primary p-4 text-sm text-secondary">{{ __('No explicitly related incidents were recorded in this window.') }}</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-secondary">{{ __('Only concrete category/resource relationships are shown. Incident titles and status are context; the incident centre contains the authorized response timeline.') }}</p>
        </section>
    </div>
</x-layouts.app>
