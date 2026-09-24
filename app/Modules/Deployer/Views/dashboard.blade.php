<x-layouts.app>
    @php
        $dashboardDialog = request()->query('dialog');
        $dashboardUrl = route('dashboard');
        $dashboardPreferencesDialogOpen = $dashboardDialog === 'customize-dashboard'
            || (old('_dashboard_preferences_form') === '1' && $errors->any());
        $dashboardProviderCreateOpen = $dashboardDialog === 'create-provider'
            || (old('_provider_form') === '1' && $errors->any());
        $dashboardServerCreateOpen = $dashboardDialog === 'create-server'
            || (old('_server_form') === '1' && $errors->any());
        $dashboardWebsiteCreateOpen = $dashboardDialog === 'create-website'
            || (old('_website_form') === '1' && $errors->any());
        $dashboardRepositoryCreateOpen = $dashboardDialog === 'create-repository'
            || (old('_repository_form') === '1' && $errors->any());
        $dashboardApplicationCreateOpen = $dashboardDialog === 'create-application'
            || (old('_project_form') === '1' && $errors->any());
        $dashboardActivityDialogOpen = $dashboardDialog === 'dashboard-activity';
        $dashboardHasProvisioning = array_sum($provisioningCounts) > 0;
        $dashboardProvisioningDialogOpen = $dashboardDialog === 'provisioning'
            && $dashboardHasProvisioning;
        $dashboardHasActiveDeployments = array_sum($activeDeploymentCounts) > 0;
        $dashboardActiveDeploymentsDialogOpen = $dashboardDialog === 'active-deployments'
            && $dashboardHasActiveDeployments;
        $dashboardHasActiveCommands = array_sum($activeCommandCounts) > 0;
        $dashboardActiveCommandsDialogOpen = $dashboardDialog === 'active-commands'
            && $dashboardHasActiveCommands;
        $dashboardHasWebhookDeliveries = array_sum($webhookDeliveryCounts) > 0;
        $dashboardWebhookActivityDialogOpen = $dashboardDialog === 'webhook-activity'
            && $dashboardHasWebhookDeliveries;
        $dashboardSystemHealthDialogOpen = $dashboardDialog === 'system-health';
        $dashboardRecipeEditOpen = $editingDashboardRecipe !== null
            || (old('_recipe_form') === 'edit' && $errors->any());
        $dashboardPreferencesDialogUrl = route('dashboard', ['dialog' => 'customize-dashboard']);
        $dashboardProviderCreateUrl = route('dashboard', ['dialog' => 'create-provider']);
        $dashboardServerCreateUrl = route('dashboard', ['dialog' => 'create-server']);
        $dashboardWebsiteCreateUrl = route('dashboard', ['dialog' => 'create-website']);
        $dashboardRepositoryCreateUrl = route('dashboard', ['dialog' => 'create-repository']);
        $dashboardApplicationCreateUrl = route('dashboard', ['dialog' => 'create-application']);
        $dashboardActivityDialogUrl = route('dashboard', ['dialog' => 'dashboard-activity']);
        $dashboardActivityContentUrl = route('activity.index', ['fragment' => 'workspace-activity']);
        $dashboardProvisioningDialogUrl = route('dashboard', ['dialog' => 'provisioning']);
        $dashboardActiveDeploymentsDialogUrl = route('dashboard', ['dialog' => 'active-deployments']);
        $dashboardActiveDeploymentsContentUrl = route('builds.index', [
            'active' => 1,
            'fragment' => 'deployment-history',
        ]);
        $dashboardSystemHealthDialogUrl = route('dashboard', ['dialog' => 'system-health']);
        $dashboardSystemHealthContentUrl = route('system-health.index', ['fragment' => 'system-health']);
        $dashboardActiveCommandsDialogUrl = route('dashboard', ['dialog' => 'active-commands']);
        $dashboardActiveCommandsContentUrl = route('commands.index', [
            'active' => 1,
            'fragment' => 'active-command-history',
        ]);
        $dashboardWebhookActivityDialogUrl = route('dashboard', ['dialog' => 'webhook-activity']);
        $dashboardWebhookActivityContentUrl = route('activity.index', [
            'category' => 'deployment',
            'fragment' => 'workspace-activity',
        ]);
        $dashboardModalOpen = [
            'provider' => $dashboardProviderCreateOpen,
            'server' => $dashboardServerCreateOpen,
            'website' => $dashboardWebsiteCreateOpen,
            'repository' => $dashboardRepositoryCreateOpen,
            'application' => $dashboardApplicationCreateOpen,
        ];
    @endphp

    <x-signal.ui.page-header
        eyebrow="{{ __('Workspace overview') }}"
        :title="__('Good morning, :name.', ['name' => auth()->user()->name])"
        :description="(auth()->user()->currentOrganization?->name ?: __('Your workspace')).' · '.__('Your infrastructure. Your next deployment. One clear view.')"
        title-id="dashboard-title"
        aria-labelledby="dashboard-title"
        data-dashboard-hero
    >
        <x-slot:actions>
            <nav class="flex shrink-0 flex-wrap items-center gap-3 sm:justify-end" aria-label="{{ __('Dashboard quick actions') }}">
            <x-signal.ui.button
                :href="$dashboardServerCreateUrl"
                data-modal-trigger="server-create-dialog"
                aria-controls="server-create-dialog"
                aria-expanded="{{ $dashboardServerCreateOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                {{ __('Create server') }}
            </x-signal.ui.button>
            <x-signal.ui.button
                :href="$dashboardWebsiteCreateUrl"
                data-modal-trigger="website-create-dialog"
                aria-controls="website-create-dialog"
                aria-expanded="{{ $dashboardWebsiteCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('Add website') }}
            </x-signal.ui.button>
            <x-signal.ui.button
                :href="$dashboardPreferencesDialogUrl"
                data-modal-trigger="dashboard-preferences-dialog"
                aria-controls="dashboard-preferences-dialog"
                aria-expanded="{{ $dashboardPreferencesDialogOpen ? 'true' : 'false' }}"
                variant="ghost"
            >
                {{ __('Customize') }}
            </x-signal.ui.button>
            </nav>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @include('dashboard._metrics')

    @include('dashboard._attention')

    <x-scenes.dashboard.preferences-dialog
        :open="$dashboardPreferencesDialogOpen"
        :widgets="$dashboardWidgets"
    />

    @include('dashboard._setup')

    @include('dashboard._quick-actions')

    <x-signal.ui.panel as="details"
        id="dashboard-operational-overview"
        class="ui-responsive-details group ui-panel mb-12 overflow-hidden"
        open
        data-responsive-details
        data-responsive-details-mobile-open="false"
        aria-labelledby="operations-overview-title"
    >
        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-5 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Last 14 days') }}</span>
                <span id="operations-overview-title" class="mt-1 block text-xl font-extrabold tracking-tight text-ink">{{ __('Operational overview') }}</span>
                <span class="mt-1 block text-sm font-normal leading-6 text-muted">{{ __('Deployment, health and plan signals for this workspace.') }}</span>
            </span>
            <span class="shrink-0 text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="ui-responsive-details__content border-t border-line p-5 lg:border-0 lg:p-0">
            <div class="mb-4 flex justify-end">
                <a href="{{ route('observability.index') }}" class="ui-link text-sm">{{ __('Open observability') }}</a>
            </div>
        <div class="grid gap-4 xl:grid-cols-[1fr_1fr_.8fr]">
            <article class="ui-card p-5" aria-labelledby="deployment-volume-title">
                <div class="flex items-start justify-between gap-3"><div><p class="ui-eyebrow">{{ __('Activity') }}</p><h3 id="deployment-volume-title" class="mt-2 font-extrabold text-ink">{{ __('Deployment volume') }}</h3><p class="mt-1 text-xs text-muted">{{ trans_choice(':count release|:count releases', $trendSummary['deployments'], ['count' => $trendSummary['deployments']]) }}</p></div><div class="text-right"><p class="text-2xl font-extrabold text-ink">{{ $trendSummary['success_rate'] === null ? '—' : $trendSummary['success_rate'].'%' }}</p><p class="text-xs text-muted">{{ __('success') }}</p></div></div>
                <div class="ui-chart ui-dashboard-trend mt-5" role="img" aria-label="{{ __('Deployment counts for each of the last fourteen days') }}">
                    @foreach($deploymentTrend as $day)
                        @php
                            $height = $day['total'] === 0 ? 3 : max(10, (int) round(($day['total'] / $deploymentTrendMaximum) * 100));
                        @endphp
                        <div class="ui-chart-column group" title="{{ $day['date'] }}: {{ $day['total'] }} deployments, {{ $day['succeeded'] }} succeeded, {{ $day['failed'] }} failed">
                            <div class="ui-chart-bar" style="height: {{ $height }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-subtle"><span>{{ $deploymentTrend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
                <p class="mt-4 border-t border-line pt-3 text-xs text-muted">{{ __('Median completed deployment') }}: <strong class="text-ink">{{ $trendSummary['median_duration'] ?? '—' }}</strong></p>
            </article>

            <article class="ui-card p-5" aria-labelledby="health-reliability-title">
                <div class="flex items-start justify-between gap-3"><div><p class="ui-eyebrow">{{ __('Reliability') }}</p><h3 id="health-reliability-title" class="mt-2 font-extrabold text-ink">{{ __('Health reliability') }}</h3><p class="mt-1 text-xs text-muted">{{ trans_choice(':count retained check|:count retained checks', $trendSummary['health_checks'], ['count' => $trendSummary['health_checks']]) }}</p></div><div class="text-right"><p class="text-2xl font-extrabold text-ink">{{ $trendSummary['health_rate'] === null ? '—' : $trendSummary['health_rate'].'%' }}</p><p class="text-xs text-muted">{{ __('passing') }}</p></div></div>
                <div class="ui-chart ui-dashboard-trend mt-5" role="img" aria-label="{{ __('Website health success rate for each of the last fourteen days') }}">
                    @foreach($healthTrend as $day)
                        <div class="ui-chart-column group" title="{{ $day['date'] }}: {{ $day['total'] }} checks, {{ $day['rate'] === null ? 'no data' : $day['rate'].'% passing' }}">
                            <div @class(['ui-chart-bar', 'bg-surface-muted' => $day['rate'] === null]) style="height: {{ $day['rate'] === null ? 3 : max(6, $day['rate']) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-subtle"><span>{{ $healthTrend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
                <p class="mt-4 border-t border-line pt-3 text-xs text-muted">{{ __('No-data days are shown as a short neutral bar and are excluded from the rate.') }}</p>
            </article>

            <article class="ui-card p-5" aria-labelledby="plan-capacity-title">
                <div class="flex items-start justify-between gap-3"><div><p class="ui-eyebrow">{{ __('Capacity') }}</p><h3 id="plan-capacity-title" class="mt-2 font-extrabold text-ink">{{ __('Plan capacity') }}</h3><p class="mt-1 text-xs text-muted">{{ __(':plan workspace', ['plan' => $billingPlan['name']]) }}</p></div><a href="{{ route('billing.index') }}" class="ui-link text-xs">{{ __('Manage') }}</a></div>
                <div class="mt-5 space-y-5">
                    @foreach($billingPlan['usage'] as $resource => $usage)
                        @php
                            $percentage = $usage['limit'] === null ? 0 : min(100, (int) round(($usage['used'] / max(1, $usage['limit'])) * 100));
                        @endphp
                        <div><div class="flex items-center justify-between gap-3 text-xs"><span class="font-bold capitalize text-ink">{{ __($resource) }}</span><span class="text-muted">{{ $usage['used'] }} / {{ $usage['limit'] ?? __('Unlimited') }}</span></div><div class="ui-progress mt-2"><span @class(['bg-danger' => !$usage['allowed']]) style="width: {{ $usage['limit'] === null ? 100 : $percentage }}%"></span></div></div>
                    @endforeach
                </div>
                <p class="mt-5 border-t border-line pt-3 text-xs leading-5 text-muted">{{ __('Limits are checked again on the server for create, import, invitation, preview, and paid-feature actions.') }}</p>
            </article>
        </div>
        </div>
    </x-signal.ui.panel>

    @php($healthOperational = $canManageSystemHealth ? $systemHealth['passed'] : $platformStatus['operational'])
    @if(in_array('status', $dashboardWidgets, true))
    <x-signal.ui.panel as="section" @class([
        'ui-panel mb-12 p-5',
        'ui-panel--success' => $healthOperational,
        'ui-panel--danger' => ! $healthOperational,
    ]) aria-labelledby="dashboard-system-health">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ __('Platform status') }}</p>
                <h2 id="dashboard-system-health" @class([
                    'mt-2 text-xl font-extrabold tracking-tight text-ink',
                ])>
                    {{ $healthOperational ? __('System operational') : __('System health needs attention') }}
                </h2>
                @if ($canManageSystemHealth)
                    <p class="mt-1 text-sm text-muted">
                        {{ trans_choice(':passed of :total check passed|:passed of :total checks passed', $systemHealth['total'], ['passed' => $systemHealth['passed_count'], 'total' => $systemHealth['total']]) }}
                    </p>
                    @if (! $systemHealth['passed'])
                        <p class="mt-2 text-sm text-danger">{{ __('Failing: :checks', ['checks' => implode(', ', $systemHealth['failed_checks'])]) }}</p>
                    @endif
                @else
                    <p class="mt-1 text-sm text-muted">
                        {{ __('Public service-level status without private infrastructure diagnostics.') }}
                    </p>
                @endif
            </div>
            @if ($canManageSystemHealth)
                <a
                    href="{{ route('system-health.index') }}"
                    data-modal-trigger="dashboard-system-health-dialog"
                    data-modal-content-url="{{ $dashboardSystemHealthContentUrl }}"
                    data-modal-history-url="{{ $dashboardSystemHealthDialogUrl }}"
                    aria-controls="dashboard-system-health-dialog"
                    aria-expanded="{{ $dashboardSystemHealthDialogOpen ? 'true' : 'false' }}"
                    class="ui-link text-sm"
                >{{ __('View system health') }}</a>
            @else
                <a href="{{ route('platform-status.show') }}" class="ui-link text-sm">{{ __('View public status') }}</a>
            @endif
        </div>
    </x-signal.ui.panel>
    @endif

    @if(in_array('providers', $dashboardWidgets, true))
    <x-signal.ui.panel as="section" class="ui-panel mb-12 p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Integrations') }}</p>
                <h2 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Provider credential health') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Latest automated and manual provider connection results.') }}</p>
            </div>
            <a href="{{ route('providers.index') }}" class="ui-link text-sm">{{ __('Manage providers') }}</a>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['status' => \App\Modules\Deployer\Models\Provider::CONNECTION_HEALTHY, 'label' => __('Healthy'), 'count' => $providerHealthCounts['healthy'], 'tone' => 'success'],
                ['status' => \App\Modules\Deployer\Models\Provider::CONNECTION_FAILED, 'label' => __('Failed'), 'count' => $providerHealthCounts['failed'], 'tone' => 'danger'],
                ['status' => \App\Modules\Deployer\Models\Provider::CONNECTION_UNCHECKED, 'label' => __('Unchecked'), 'count' => $providerHealthCounts['unchecked'], 'tone' => 'neutral'],
            ] as $health)
                <a href="{{ route('providers.index', ['connection' => $health['status']]) }}" class="ui-card ui-card--interactive flex items-center justify-between gap-3 p-4">
                    <span class="text-2xl font-extrabold tracking-tight text-ink">{{ $health['count'] }}</span>
                    <x-signal.ui.badge :tone="$health['tone']">{{ $health['label'] }}</x-signal.ui.badge>
                </a>
            @endforeach
        </div>
    </x-signal.ui.panel>
    @endif

    @php($provisioningTotal = array_sum($provisioningCounts))
    @if ($provisioningTotal > 0)
        <x-signal.ui.panel as="section" class="ui-panel ui-panel--warning mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Resource lifecycle') }}</p>
                    <h2 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Infrastructure provisioning') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ trans_choice(':count resource is being prepared|:count resources are being prepared', $provisioningTotal, ['count' => $provisioningTotal]) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm font-medium">
                    <a
                        href="{{ route('servers.index', ['provisioning' => 1]) }}"
                        data-modal-trigger="dashboard-provisioning-dialog"
                        data-modal-history-url="{{ $dashboardProvisioningDialogUrl }}"
                        aria-controls="dashboard-provisioning-dialog"
                        aria-expanded="{{ $dashboardProvisioningDialogOpen ? 'true' : 'false' }}"
                        class="ui-link"
                    >{{ __('View provisioning servers') }}</a>
                    <a
                        href="{{ route('websites.index', ['provisioning' => 1]) }}"
                        data-modal-trigger="dashboard-provisioning-dialog"
                        data-modal-history-url="{{ $dashboardProvisioningDialogUrl }}"
                        aria-controls="dashboard-provisioning-dialog"
                        aria-expanded="{{ $dashboardProvisioningDialogOpen ? 'true' : 'false' }}"
                        class="ui-link"
                    >{{ __('View provisioning websites') }}</a>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="ui-stat p-3">
                    <span class="block text-xl font-extrabold text-ink">{{ $provisioningCounts['servers'] }}</span>
                    <span class="text-xs font-semibold uppercase text-muted">{{ __('Servers') }}</span>
                </div>
                <div class="ui-stat p-3">
                    <span class="block text-xl font-extrabold text-ink">{{ $provisioningCounts['websites'] }}</span>
                    <span class="text-xs font-semibold uppercase text-muted">{{ __('Websites') }}</span>
                </div>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($provisioningResources as $resource)
                    @php($isServer = $resource instanceof \App\Modules\Deployer\Models\Server)
                    <a
                        href="{{ $isServer ? route('servers.show', $resource) : route('websites.show', $resource) }}"
                        class="ui-card ui-card--interactive flex items-center justify-between gap-4 p-4"
                    >
                        <div>
                            <span class="block font-bold text-ink">{{ $isServer ? $resource->label : $resource->name }}</span>
                            <span class="mt-1 block text-sm text-muted">{{ $isServer ? __('Server') : __('Website') }}</span>
                        </div>
                        <div class="text-right text-xs text-muted">
                            <span class="block font-semibold uppercase">{{ str($resource->provisioning_status)->replace('_', ' ') }}</span>
                            <span class="mt-1 block">{{ $resource->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($provisioningTotal > $provisioningResources->count())
                <p class="mt-4 text-sm text-muted">
                    {{ trans_choice(':count more resource is provisioning|:count more resources are provisioning', $provisioningTotal - $provisioningResources->count(), ['count' => $provisioningTotal - $provisioningResources->count()]) }}
                </p>
            @endif
        </x-signal.ui.panel>
    @endif

    @php($activeDeploymentTotal = array_sum($activeDeploymentCounts))
    @if ($activeDeploymentTotal > 0)
        <x-signal.ui.panel as="section" class="ui-panel mb-12 p-5" aria-labelledby="dashboard-deployment-timeline-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Deployment timeline') }}</p>
                    <h2 id="dashboard-deployment-timeline-title" class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Active deployments') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ trans_choice(':count deployment is in progress|:count deployments are in progress', $activeDeploymentTotal, ['count' => $activeDeploymentTotal]) }}
                    </p>
                </div>
                <a
                    href="{{ route('builds.index', ['active' => 1]) }}"
                    data-modal-trigger="dashboard-active-deployments-dialog"
                    data-modal-content-url="{{ $dashboardActiveDeploymentsContentUrl }}"
                    data-modal-history-url="{{ $dashboardActiveDeploymentsDialogUrl }}"
                    aria-controls="dashboard-active-deployments-dialog"
                    aria-expanded="{{ $dashboardActiveDeploymentsDialogOpen ? 'true' : 'false' }}"
                    class="ui-link text-sm"
                >{{ __('View active deployments') }}</a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    \App\Modules\Deployer\Models\Build::STATUS_QUEUED => __('Queued'),
                    \App\Modules\Deployer\Models\Build::STATUS_DEPLOYING => __('Deploying'),
                    \App\Modules\Deployer\Models\Build::STATUS_RUNNING => __('Running'),
                    \App\Modules\Deployer\Models\Build::STATUS_TIMING_OUT => __('Timing out'),
                ] as $status => $label)
                    <div class="ui-stat p-3">
                        <span class="block text-xl font-extrabold text-ink">{{ $activeDeploymentCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-muted">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="ui-timeline mt-5 space-y-3" aria-label="{{ __('Deployment timeline') }}">
                @foreach ($activeDeployments as $build)
                    <a href="{{ route('builds.show', $build) }}" class="ui-timeline-item ui-card ui-card--interactive flex items-center justify-between gap-4 p-4">
                        <div>
                            <span class="block font-bold text-ink">{{ $build->repository->name }}</span>
                            <span class="mt-1 block text-sm text-muted">
                                {{ $build->repository->website?->name }}
                                @if ($build->repository->website?->server)
                                    &middot; {{ $build->repository->website->server->label }}
                                @endif
                            </span>
                        </div>
                        <div class="text-right text-xs text-muted">
                            <span class="block font-semibold uppercase">{{ str($build->status)->replace('_', ' ') }}</span>
                            <span class="mt-1 block">{{ $build->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($activeDeploymentTotal > $activeDeployments->count())
                <a
                    href="{{ route('builds.index', ['active' => 1]) }}"
                    data-modal-trigger="dashboard-active-deployments-dialog"
                    data-modal-content-url="{{ $dashboardActiveDeploymentsContentUrl }}"
                    data-modal-history-url="{{ $dashboardActiveDeploymentsDialogUrl }}"
                    aria-controls="dashboard-active-deployments-dialog"
                    aria-expanded="{{ $dashboardActiveDeploymentsDialogOpen ? 'true' : 'false' }}"
                    class="ui-link mt-4 inline-flex text-sm"
                >
                    {{ trans_choice(':count more active deployment|:count more active deployments', $activeDeploymentTotal - $activeDeployments->count(), ['count' => $activeDeploymentTotal - $activeDeployments->count()]) }}
                </a>
            @endif
        </x-signal.ui.panel>
    @endif

    @php($webhookDeliveryTotal = array_sum($webhookDeliveryCounts))
    @if ($webhookDeliveryTotal > 0)
        <x-signal.ui.panel as="section" class="ui-panel mb-12 p-5" aria-labelledby="dashboard-webhook-deliveries-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Repository activity') }}</p>
                    <h2 id="dashboard-webhook-deliveries-title" class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Webhook deliveries') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ trans_choice(':count delivery received in the last 24 hours|:count deliveries received in the last 24 hours', $webhookDeliveryTotal, ['count' => $webhookDeliveryTotal]) }}
                    </p>
                </div>
                <a
                    href="{{ route('activity.index', ['category' => 'deployment']) }}"
                    data-modal-trigger="dashboard-webhook-activity-dialog"
                    data-modal-content-url="{{ $dashboardWebhookActivityContentUrl }}"
                    data-modal-history-url="{{ $dashboardWebhookActivityDialogUrl }}"
                    aria-controls="dashboard-webhook-activity-dialog"
                    aria-expanded="{{ $dashboardWebhookActivityDialogOpen ? 'true' : 'false' }}"
                    class="ui-link text-sm"
                >
                    {{ __('View deployment activity') }}
                </a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-6">
                @foreach ([
                    \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_QUEUED => __('Queued'),
                    \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_PENDING => __('Pending'),
                    \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_SKIPPED => __('Skipped'),
                    \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE => __('Unavailable'),
                    \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED => __('Superseded'),
                    \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_RECEIVED => __('Received'),
                ] as $status => $label)
                    <div class="ui-stat p-3">
                        <span class="block text-xl font-extrabold text-ink">{{ $webhookDeliveryCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-muted">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($recentWebhookDeliveries as $delivery)
                    <a
                        href="{{ route('repositories.show', ['repository' => $delivery->repository, 'delivery_status' => $delivery->status]) }}#webhook-deliveries"
                        class="ui-card ui-card--interactive flex items-center justify-between gap-4 p-4"
                    >
                        <div>
                            <span class="block font-bold text-ink">{{ $delivery->repository->name }}</span>
                            <span class="mt-1 block text-sm text-muted">{{ __('Delivery #:id', ['id' => $delivery->id]) }}</span>
                        </div>
                        <div class="text-right text-xs text-muted">
                            <span class="block font-semibold uppercase">{{ $delivery->status }}</span>
                            <span class="mt-1 block">{{ $delivery->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($webhookDeliveryTotal > $recentWebhookDeliveries->count())
                <p class="mt-4 text-sm text-muted">
                    {{ trans_choice(':count more delivery is available in repository history|:count more deliveries are available in repository history', $webhookDeliveryTotal - $recentWebhookDeliveries->count(), ['count' => $webhookDeliveryTotal - $recentWebhookDeliveries->count()]) }}
                </p>
            @endif
        </x-signal.ui.panel>
    @endif

    @php($activeCommandTotal = array_sum($activeCommandCounts))
    @if ($activeCommandTotal > 0)
        <x-signal.ui.panel as="section" class="ui-panel mb-12 p-5" aria-labelledby="dashboard-active-commands-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Operations') }}</p>
                    <h2 id="dashboard-active-commands-title" class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Active server commands') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ trans_choice(':count command is active|:count commands are active', $activeCommandTotal, ['count' => $activeCommandTotal]) }}
                    </p>
                </div>
                <a
                    href="{{ route('commands.index', ['active' => 1]) }}"
                    data-modal-trigger="dashboard-active-commands-dialog"
                    data-modal-content-url="{{ $dashboardActiveCommandsContentUrl }}"
                    data-modal-history-url="{{ $dashboardActiveCommandsDialogUrl }}"
                    aria-controls="dashboard-active-commands-dialog"
                    aria-expanded="{{ $dashboardActiveCommandsDialogOpen ? 'true' : 'false' }}"
                    class="ui-link text-sm"
                >
                    {{ __('Open Command Center') }}
                </a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                @foreach ([
                    \App\Modules\Deployer\Models\ServerCommandExecution::STATUS_QUEUED => __('Queued'),
                    \App\Modules\Deployer\Models\ServerCommandExecution::STATUS_RUNNING => __('Running'),
                ] as $status => $label)
                    <div class="ui-stat p-3">
                        <span class="block text-xl font-extrabold text-ink">{{ $activeCommandCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-muted">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($activeCommands as $execution)
                    <a
                        href="{{ route('servers.commands.index', ['server' => $execution->server, 'status' => $execution->status]) }}"
                        class="ui-card ui-card--interactive flex items-center justify-between gap-4 p-4"
                    >
                        <div>
                            <span class="block font-bold text-ink">{{ $execution->server->label }}</span>
                            <span class="mt-1 block text-sm text-muted">{{ __('Command #:id', ['id' => $execution->id]) }}</span>
                        </div>
                        <div class="text-right text-xs text-muted">
                            <span class="block font-semibold uppercase">{{ $execution->status }}</span>
                            <span class="mt-1 block">{{ $execution->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($activeCommandTotal > $activeCommands->count())
                <a
                    href="{{ route('commands.index', ['active' => 1]) }}"
                    data-modal-trigger="dashboard-active-commands-dialog"
                    data-modal-content-url="{{ $dashboardActiveCommandsContentUrl }}"
                    data-modal-history-url="{{ $dashboardActiveCommandsDialogUrl }}"
                    aria-controls="dashboard-active-commands-dialog"
                    aria-expanded="{{ $dashboardActiveCommandsDialogOpen ? 'true' : 'false' }}"
                    class="ui-link mt-4 inline-flex text-sm"
                >
                    {{ trans_choice(':count more active command is available in server history|:count more active commands are available in server history', $activeCommandTotal - $activeCommands->count(), ['count' => $activeCommandTotal - $activeCommands->count()]) }}
                </a>
            @endif
        </x-signal.ui.panel>
    @endif

    @if ($communityReportCount > 0)
        <x-signal.ui.panel as="section" class="ui-panel ui-panel--danger mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Community safety') }}</p>
                    <h2 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Community recipe feedback') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ trans_choice(':count community report needs review|:count community reports need review', $communityReportCount, ['count' => $communityReportCount]) }}
                        &middot;
                        {{ trans_choice(':count published recipe affected|:count published recipes affected', $reportedGalleryRecipeCount, ['count' => $reportedGalleryRecipeCount]) }}
                    </p>
                </div>
                <a href="{{ route('gallery.reports.index') }}" class="ui-link text-sm">
                    {{ __('Open feedback inbox') }}
                </a>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <a href="{{ route('gallery.reports.index') }}" class="ui-stat ui-card--interactive p-3">
                    <span class="block text-xl font-extrabold text-ink">{{ $communityReportCount }}</span>
                    <span class="text-xs font-semibold uppercase text-muted">{{ __('All needing review') }}</span>
                </a>
                <a href="{{ route('gallery.reports.index', ['reason' => 'security', 'sort' => 'priority']) }}" class="ui-stat ui-card--interactive p-3">
                    <span class="block text-xl font-extrabold text-ink">{{ $communityReportAttention['security'] }}</span>
                    <span class="text-xs font-semibold uppercase text-muted">{{ __('Security reports') }}</span>
                </a>
                <a href="{{ route('gallery.reports.index', ['age' => '7d', 'sort' => 'oldest']) }}" class="ui-stat ui-card--interactive p-3">
                    <span class="block text-xl font-extrabold text-ink">{{ $communityReportAttention['stale'] }}</span>
                    <span class="text-xs font-semibold uppercase text-muted">{{ __('Open at least 7 days') }}</span>
                </a>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($reportedGalleryRecipes as $recipe)
                    <a href="{{ route('gallery.reports.index', ['recipe' => $recipe->id]) }}" class="ui-card ui-card--interactive flex items-center justify-between gap-4 p-4">
                        <div>
                            <span class="block font-bold text-ink">{{ $recipe->name }}</span>
                            <span class="mt-1 block text-sm text-muted">{{ str($recipe->category)->headline() }}</span>
                        </div>
                        <span class="ui-badge ui-badge-danger">
                            {{ trans_choice(':count report|:count reports', $recipe->reports_count, ['count' => $recipe->reports_count]) }}
                        </span>
                    </a>
                @endforeach
            </div>

            @if ($reportedGalleryRecipeCount > $reportedGalleryRecipes->count())
                <a href="{{ route('gallery.reports.index') }}" class="ui-link mt-4 inline-flex text-sm">
                    {{ trans_choice(':count more reported recipe|:count more reported recipes', $reportedGalleryRecipeCount - $reportedGalleryRecipes->count(), ['count' => $reportedGalleryRecipeCount - $reportedGalleryRecipes->count()]) }}
                </a>
            @endif
        </x-signal.ui.panel>
    @endif

    @if ($recipeUpdateCount > 0)
        <x-signal.ui.panel as="section" class="ui-panel ui-panel--warning mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Maintenance') }}</p>
                    <h2 class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Recipe updates') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ trans_choice(':count installed recipe has a gallery update|:count installed recipes have gallery updates', $recipeUpdateCount, ['count' => $recipeUpdateCount]) }}
                    </p>
                </div>
                <a href="{{ route('gallery.index', ['scope' => 'updates']) }}" class="ui-link text-sm">
                    {{ __('View all updates') }}
                </a>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($recipeUpdates as $recipe)
                    @php($installedRecipe = $recipe->installs->first(fn ($copy) => $copy->hasGalleryUpdate($recipe)))
                    @continue($installedRecipe === null)
                    <div class="ui-card p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <span class="block font-bold text-ink">{{ $recipe->name }}</span>
                                <span class="mt-1 block text-sm text-muted">
                                    {{ str($recipe->category)->headline() }} &middot; {{ __('by :author', ['author' => $recipe->user->name]) }}
                                </span>
                                <span class="mt-1 block text-xs text-muted">
                                    {{ __('Installed as :name', ['name' => $installedRecipe->name]) }}
                                    &middot; {{ __('updated :time', ['time' => $recipe->gallery_revision_at->diffForHumans()]) }}
                                </span>
                            </div>
                            <div class="flex gap-3 text-sm font-medium">
                                <a href="{{ route('gallery.compare', ['recipe' => $recipe, 'copy' => $installedRecipe]) }}" class="ui-link text-sm">
                                    {{ __('Review changes') }}
                                </a>
                                @php($recipeEditDialogId = 'recipe-edit-dialog-'.$installedRecipe->id)
                                <a
                                    href="{{ route('dashboard', ['dialog' => 'edit-recipe-'.$installedRecipe->id]) }}"
                                    data-modal-trigger="{{ $recipeEditDialogId }}"
                                    data-modal-content-url="{{ route('recipes.edit', ['recipe' => $installedRecipe, 'dialog' => 'edit-recipe-'.$installedRecipe->id, 'fragment' => 1, 'return_to' => $dashboardUrl]) }}"
                                    aria-controls="{{ $recipeEditDialogId }}"
                                    aria-expanded="{{ $dashboardRecipeEditOpen && $editingDashboardRecipe?->id === $installedRecipe->id ? 'true' : 'false' }}"
                                    class="ui-link text-sm"
                                >
                                    {{ __('Edit copy') }}
                                </a>
                    </div>
                    <x-scenes.recipes.edit-dialog
                        :recipe="$editingDashboardRecipe?->id === $installedRecipe->id ? $editingDashboardRecipe : $installedRecipe"
                        :id="$recipeEditDialogId"
                        :open="$dashboardRecipeEditOpen && $editingDashboardRecipe?->id === $installedRecipe->id"
                        :cancel-url="$dashboardUrl"
                        :content-url="route('recipes.edit', ['recipe' => $installedRecipe, 'dialog' => 'edit-recipe-'.$installedRecipe->id, 'fragment' => 1, 'return_to' => $dashboardUrl])"
                        field-prefix="dashboard-recipe-edit-"
                    />
                </div>
                    </div>
                @endforeach
            </div>

            @if ($recipeUpdateCount > $recipeUpdates->count())
                <a href="{{ route('gallery.index', ['scope' => 'updates']) }}" class="ui-link mt-4 inline-flex text-sm">
                    {{ trans_choice(':count more recipe update|:count more recipe updates', $recipeUpdateCount - $recipeUpdates->count(), ['count' => $recipeUpdateCount - $recipeUpdates->count()]) }}
                </a>
            @endif
        </x-signal.ui.panel>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <section>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-extrabold tracking-tight text-ink">{{ __('Recent websites') }}</h2>
                <a href="{{ route('websites.index') }}" class="ui-link text-sm">{{ __('View all') }}</a>
            </div>

            @forelse ($recentWebsites as $website)
                <a href="{{ route('websites.show', $website) }}" class="ui-card ui-card--interactive mb-3 flex items-center justify-between p-4">
                    <div>
                        <p class="font-bold text-ink">{{ $website->name }}</p>
                        <p class="text-sm text-muted">{{ $website->url }}</p>
                    </div>
                    <span class="text-sm text-muted">{{ $website->server?->label ?? __('No server') }}</span>
                </a>
            @empty
                <x-lists.empty
                    :title="__('No websites yet')"
                    :description="__('Create a website to begin configuring deployments.')"
                >
                    <x-slot:button>
                        <x-signal.ui.button
                            :href="$dashboardWebsiteCreateUrl"
                            data-modal-trigger="website-create-dialog"
                            aria-controls="website-create-dialog"
                            aria-expanded="{{ $dashboardWebsiteCreateOpen ? 'true' : 'false' }}"
                            variant="primary"
                        >{{ __('Add website') }}</x-signal.ui.button>
                    </x-slot:button>
                </x-lists.empty>
            @endforelse
        </section>

        <section>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-extrabold tracking-tight text-ink">{{ __('Recent builds') }}</h2>
                <a href="{{ route('builds.index') }}" class="ui-link text-sm">{{ __('View all') }}</a>
            </div>

            @forelse ($recentBuilds as $build)
                <a href="{{ route('builds.show', $build) }}" class="ui-card ui-card--interactive mb-3 flex items-center justify-between p-4">
                    <div>
                        <p class="font-bold text-ink">{{ $build->repository->name }}</p>
                        <p class="text-sm text-muted">{{ $build->repository->website?->name }}</p>
                    </div>
                    <div class="text-right text-sm text-muted">
                        <span class="block uppercase">{{ $build->status }}</span>
                        <span>{{ ($build->built_at ?? $build->created_at)->diffForHumans() }}</span>
                    </div>
                </a>
            @empty
                <x-lists.empty
                    :title="__('No builds yet')"
                    :description="__('Your latest repository deployments will appear here.')"
                />
            @endforelse
        </section>
    </div>

    <section class="mt-12">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-xl font-extrabold tracking-tight text-ink">{{ __('Recent activity') }}</h2>
            <a
                href="{{ route('activity.index') }}"
                data-modal-trigger="dashboard-activity-dialog"
                data-modal-content-url="{{ $dashboardActivityContentUrl }}"
                data-modal-history-url="{{ $dashboardActivityDialogUrl }}"
                aria-controls="dashboard-activity-dialog"
                aria-expanded="{{ $dashboardActivityDialogOpen ? 'true' : 'false' }}"
                class="ui-link text-sm"
            >{{ __('View all') }}</a>
        </div>

        <x-activity-feed :events="$recentEvents" />
    </section>

    <x-scenes.providers.create-dialog
        :open="$dashboardProviderCreateOpen"
        :cancel-url="$dashboardUrl"
    />

    <x-scenes.servers.create-dialog
        :types="$dashboardCreationData['server']['types']"
        :providers="$dashboardCreationData['server']['providers']"
        :sizes="$dashboardCreationData['server']['sizes']"
        :images="$dashboardCreationData['server']['images']"
        :regions="$dashboardCreationData['server']['regions']"
        :recipes="$dashboardCreationData['server']['recipes']"
        :plan-usage="$dashboardCreationData['server']['planUsage']"
        :index-query="[]"
        :open="$dashboardServerCreateOpen"
        :cancel-url="$dashboardUrl"
    />

    <x-scenes.websites.create-dialog
        :servers="$dashboardCreationData['website']['servers']"
        :plan-usage="$dashboardCreationData['website']['planUsage']"
        :website-index-query="[]"
        :website-store-url="route('websites.store', ['dialog' => 'create-website'])"
        :open="$dashboardWebsiteCreateOpen"
        :cancel-url="$dashboardUrl"
    />

    <x-scenes.repositories.create-dialog
        :providers="$dashboardCreationData['repository']['providers']"
        :websites="$dashboardCreationData['repository']['websites']"
        :index-query="[]"
        :open="$dashboardRepositoryCreateOpen"
        :cancel-url="$dashboardUrl"
    />

    <x-scenes.projects.create-dialog
        :templates="$dashboardCreationData['application']['templates']"
        :open="$dashboardApplicationCreateOpen"
        :cancel-url="$dashboardUrl"
    />

    <x-signal.overlays.modal
        id="dashboard-activity-dialog"
        :title="__('Workspace activity')"
        :description="__('Review recent workspace events without leaving the dashboard.')"
        :open="$dashboardActivityDialogOpen"
        body-class="p-0"
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading workspace activity…') }}</p>
        </div>
    </x-signal.overlays.modal>

    @if ($dashboardHasActiveDeployments)
        <x-signal.overlays.modal
            id="dashboard-active-deployments-dialog"
            :title="__('Active deployments')"
            :description="__('Review active deployment progress without leaving the dashboard.')"
            :open="$dashboardActiveDeploymentsDialogOpen"
            body-class="p-0"
        >
            <div data-modal-content>
                <p class="p-5 text-sm text-muted">{{ __('Loading active deployments…') }}</p>
            </div>
        </x-signal.overlays.modal>
    @endif

    @if ($dashboardHasActiveCommands)
        <x-signal.overlays.modal
            id="dashboard-active-commands-dialog"
            :title="__('Active server commands')"
            :description="__('Review active command status without leaving the dashboard.')"
            :open="$dashboardActiveCommandsDialogOpen"
            body-class="p-0"
        >
            <div data-modal-content>
                <p class="p-5 text-sm text-muted">{{ __('Loading active command history…') }}</p>
            </div>
        </x-signal.overlays.modal>
    @endif

    @if ($dashboardHasWebhookDeliveries)
        <x-signal.overlays.modal
            id="dashboard-webhook-activity-dialog"
            :title="__('Deployment activity')"
            :description="__('Review webhook-related deployment events without leaving the dashboard.')"
            :open="$dashboardWebhookActivityDialogOpen"
            body-class="p-0"
        >
            <div data-modal-content>
                <p class="p-5 text-sm text-muted">{{ __('Loading deployment activity…') }}</p>
            </div>
        </x-signal.overlays.modal>
    @endif

    @if ($dashboardHasProvisioning)
        <x-signal.overlays.modal
            id="dashboard-provisioning-dialog"
            :title="__('Infrastructure provisioning')"
            :description="__('Review resources being prepared without leaving the dashboard.')"
            :open="$dashboardProvisioningDialogOpen"
            body-class="p-0"
        >
            <div class="p-5">
                @include('dashboard._provisioning-dialog-content')
            </div>
        </x-signal.overlays.modal>
    @endif

    @if ($canManageSystemHealth)
        <x-signal.overlays.modal
            id="dashboard-system-health-dialog"
            :title="__('System health')"
            :description="__('Review a fresh sanitized diagnostic snapshot without leaving the dashboard.')"
            :open="$dashboardSystemHealthDialogOpen"
            body-class="p-0"
        >
            <div data-modal-content>
                <p class="p-5 text-sm text-muted">{{ __('Loading system health…') }}</p>
            </div>
        </x-signal.overlays.modal>
    @endif

</x-layouts.app>
