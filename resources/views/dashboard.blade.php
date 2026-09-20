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
        $dashboardRecipeEditOpen = $editingDashboardRecipe !== null
            || (old('_recipe_form') === 'edit' && $errors->any());
        $dashboardPreferencesDialogUrl = route('dashboard', ['dialog' => 'customize-dashboard']);
        $dashboardProviderCreateUrl = route('dashboard', ['dialog' => 'create-provider']);
        $dashboardServerCreateUrl = route('dashboard', ['dialog' => 'create-server']);
        $dashboardWebsiteCreateUrl = route('dashboard', ['dialog' => 'create-website']);
        $dashboardRepositoryCreateUrl = route('dashboard', ['dialog' => 'create-repository']);
        $dashboardApplicationCreateUrl = route('dashboard', ['dialog' => 'create-application']);
        $dashboardModalOpen = [
            'provider' => $dashboardProviderCreateOpen,
            'server' => $dashboardServerCreateOpen,
            'website' => $dashboardWebsiteCreateOpen,
            'repository' => $dashboardRepositoryCreateOpen,
            'application' => $dashboardApplicationCreateOpen,
        ];
    @endphp

    <header class="ui-dashboard-hero ui-card mb-6 flex flex-col gap-5 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6" aria-labelledby="dashboard-title" data-dashboard-hero>
        <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Workspace overview') }}</p>
            <p class="mt-2 text-sm text-secondary">{{ __('Welcome back, :name', ['name' => auth()->user()->name]) }}</p>
            <h1 id="dashboard-title" class="mt-1 break-words text-2xl font-bold text-primary">{{ auth()->user()->currentOrganization?->name ?: __('Dashboard') }}</h1>
            <p class="mt-1 text-sm text-secondary">{{ __('Your infrastructure. Your next deployment. One clear view.') }}</p>
        </div>
        <nav class="flex shrink-0 flex-wrap gap-2" aria-label="{{ __('Dashboard quick actions') }}">
            <x-ui.button
                :href="$dashboardServerCreateUrl"
                data-modal-trigger="server-create-dialog"
                aria-controls="server-create-dialog"
                aria-expanded="{{ $dashboardServerCreateOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                {{ __('Create server') }}
            </x-ui.button>
            <x-ui.button
                :href="$dashboardWebsiteCreateUrl"
                data-modal-trigger="website-create-dialog"
                aria-controls="website-create-dialog"
                aria-expanded="{{ $dashboardWebsiteCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('Add website') }}
            </x-ui.button>
            <x-ui.button
                :href="$dashboardPreferencesDialogUrl"
                data-modal-trigger="dashboard-preferences-dialog"
                aria-controls="dashboard-preferences-dialog"
                aria-expanded="{{ $dashboardPreferencesDialogOpen ? 'true' : 'false' }}"
                variant="ghost"
            >
                {{ __('Customize') }}
            </x-ui.button>
        </nav>
    </header>

    @include('dashboard._attention')

    <x-scenes.dashboard.preferences-dialog
        :open="$dashboardPreferencesDialogOpen"
        :widgets="$dashboardWidgets"
    />

    @include('dashboard._setup')

    @include('dashboard._quick-actions')

    @include('dashboard._metrics')

    <details
        id="dashboard-operational-overview"
        class="ui-responsive-details group ui-card mb-12 overflow-hidden"
        open
        data-responsive-details
        data-responsive-details-mobile-open="false"
        aria-labelledby="operations-overview-title"
    >
        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Last 14 days') }}</span>
                <span id="operations-overview-title" class="mt-1 block text-xl font-semibold text-primary">{{ __('Operational overview') }}</span>
                <span class="mt-1 block text-sm font-normal leading-6 text-secondary">{{ __('Deployment, health and plan signals for this workspace.') }}</span>
            </span>
            <span class="shrink-0 text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="ui-responsive-details__content border-t border-primary p-5 lg:border-0 lg:p-0">
            <div class="mb-4 flex justify-end">
                <a href="{{ route('observability.index') }}" class="text-sm font-bold text-ternary underline">{{ __('Open observability') }}</a>
            </div>
        <div class="grid gap-4 xl:grid-cols-[1fr_1fr_.8fr]">
            <article class="ui-card p-5" aria-labelledby="deployment-volume-title">
                <div class="flex items-start justify-between gap-3"><div><h3 id="deployment-volume-title" class="font-black text-primary">{{ __('Deployment volume') }}</h3><p class="mt-1 text-xs text-secondary">{{ trans_choice(':count release|:count releases', $trendSummary['deployments'], ['count' => $trendSummary['deployments']]) }}</p></div><div class="text-right"><p class="text-2xl font-black text-primary">{{ $trendSummary['success_rate'] === null ? '—' : $trendSummary['success_rate'].'%' }}</p><p class="text-xs text-secondary">{{ __('success') }}</p></div></div>
                <div class="mt-5 flex h-28 items-end gap-1.5" role="img" aria-label="{{ __('Deployment counts for each of the last fourteen days') }}">
                    @foreach($deploymentTrend as $day)
                        @php
                            $height = $day['total'] === 0 ? 3 : max(10, (int) round(($day['total'] / $deploymentTrendMaximum) * 100));
                        @endphp
                        <div class="group flex h-full min-w-0 flex-1 items-end" title="{{ $day['date'] }}: {{ $day['total'] }} deployments, {{ $day['succeeded'] }} succeeded, {{ $day['failed'] }} failed">
                            <div class="w-full rounded-t bg-ternary transition-opacity group-hover:opacity-75" style="height: {{ $height }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-secondary"><span>{{ $deploymentTrend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
                <p class="mt-4 border-t border-primary pt-3 text-xs text-secondary">{{ __('Median completed deployment') }}: <strong class="text-primary">{{ $trendSummary['median_duration'] ?? '—' }}</strong></p>
            </article>

            <article class="ui-card p-5" aria-labelledby="health-reliability-title">
                <div class="flex items-start justify-between gap-3"><div><h3 id="health-reliability-title" class="font-black text-primary">{{ __('Health reliability') }}</h3><p class="mt-1 text-xs text-secondary">{{ trans_choice(':count retained check|:count retained checks', $trendSummary['health_checks'], ['count' => $trendSummary['health_checks']]) }}</p></div><div class="text-right"><p class="text-2xl font-black text-primary">{{ $trendSummary['health_rate'] === null ? '—' : $trendSummary['health_rate'].'%' }}</p><p class="text-xs text-secondary">{{ __('passing') }}</p></div></div>
                <div class="mt-5 flex h-28 items-end gap-1.5" role="img" aria-label="{{ __('Website health success rate for each of the last fourteen days') }}">
                    @foreach($healthTrend as $day)
                        <div class="group flex h-full min-w-0 flex-1 items-end" title="{{ $day['date'] }}: {{ $day['total'] }} checks, {{ $day['rate'] === null ? 'no data' : $day['rate'].'% passing' }}">
                            <div @class(['w-full rounded-t transition-opacity group-hover:opacity-75', 'bg-secondary' => $day['rate'] === null, 'bg-ternary' => $day['rate'] !== null]) style="height: {{ $day['rate'] === null ? 3 : max(6, $day['rate']) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-[10px] font-bold uppercase text-secondary"><span>{{ $healthTrend->first()['date'] }}</span><span>{{ __('Today') }}</span></div>
                <p class="mt-4 border-t border-primary pt-3 text-xs text-secondary">{{ __('No-data days are shown as a short neutral bar and are excluded from the rate.') }}</p>
            </article>

            <article class="ui-card p-5" aria-labelledby="plan-capacity-title">
                <div class="flex items-start justify-between gap-3"><div><h3 id="plan-capacity-title" class="font-black text-primary">{{ __('Plan capacity') }}</h3><p class="mt-1 text-xs text-secondary">{{ __(':plan workspace', ['plan' => $billingPlan['name']]) }}</p></div><a href="{{ route('billing.index') }}" class="text-xs font-bold text-ternary underline">{{ __('Manage') }}</a></div>
                <div class="mt-5 space-y-5">
                    @foreach($billingPlan['usage'] as $resource => $usage)
                        @php
                            $percentage = $usage['limit'] === null ? 0 : min(100, (int) round(($usage['used'] / max(1, $usage['limit'])) * 100));
                        @endphp
                        <div><div class="flex items-center justify-between gap-3 text-xs"><span class="font-bold capitalize text-primary">{{ __($resource) }}</span><span class="text-secondary">{{ $usage['used'] }} / {{ $usage['limit'] ?? __('Unlimited') }}</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-secondary"><div @class(['h-full rounded-full', 'bg-red-500' => !$usage['allowed'], 'bg-ternary' => $usage['allowed']]) style="width: {{ $usage['limit'] === null ? 100 : $percentage }}%"></div></div></div>
                    @endforeach
                </div>
                <p class="mt-5 border-t border-primary pt-3 text-xs leading-5 text-secondary">{{ __('Limits are checked again on the server for create, import, invitation, preview, and paid-feature actions.') }}</p>
            </article>
        </div>
        </div>
    </details>

    @php($healthOperational = $canManageSystemHealth ? $systemHealth['passed'] : $platformStatus['operational'])
    @if(in_array('status', $dashboardWidgets, true))
    <section @class([
        'ui-alert mb-12 p-5',
        'ui-alert--success' => $healthOperational,
        'ui-alert--danger' => ! $healthOperational,
    ]) aria-labelledby="dashboard-system-health">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase text-secondary">{{ __('Platform status') }}</p>
                <h2 id="dashboard-system-health" @class([
                    'mt-1 text-xl font-semibold',
                    'text-primary',
                ])>
                    {{ $healthOperational ? __('System operational') : __('System health needs attention') }}
                </h2>
                @if ($canManageSystemHealth)
                    <p class="mt-1 text-sm text-secondary">
                        {{ trans_choice(':passed of :total check passed|:passed of :total checks passed', $systemHealth['total'], ['passed' => $systemHealth['passed_count'], 'total' => $systemHealth['total']]) }}
                    </p>
                    @if (! $systemHealth['passed'])
                        <p class="mt-2 text-sm">{{ __('Failing: :checks', ['checks' => implode(', ', $systemHealth['failed_checks'])]) }}</p>
                    @endif
                @else
                    <p class="mt-1 text-sm text-secondary">
                        {{ __('Public service-level status without private infrastructure diagnostics.') }}
                    </p>
                @endif
            </div>
            <a href="{{ $canManageSystemHealth ? route('system-health.index') : route('platform-status.show') }}" class="text-sm font-medium text-ternary underline">
                {{ $canManageSystemHealth ? __('View system health') : __('View public status') }}
            </a>
        </div>
    </section>
    @endif

    @if(in_array('providers', $dashboardWidgets, true))
    <section class="ui-card mb-12 p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-primary">{{ __('Provider credential health') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ __('Latest automated and manual provider connection results.') }}</p>
            </div>
            <a href="{{ route('providers.index') }}" class="text-sm font-medium text-ternary underline">{{ __('Manage providers') }}</a>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['status' => \App\Models\Provider::CONNECTION_HEALTHY, 'label' => __('Healthy'), 'count' => $providerHealthCounts['healthy'], 'tone' => 'success'],
                ['status' => \App\Models\Provider::CONNECTION_FAILED, 'label' => __('Failed'), 'count' => $providerHealthCounts['failed'], 'tone' => 'danger'],
                ['status' => \App\Models\Provider::CONNECTION_UNCHECKED, 'label' => __('Unchecked'), 'count' => $providerHealthCounts['unchecked'], 'tone' => 'neutral'],
            ] as $health)
                <a href="{{ route('providers.index', ['connection' => $health['status']]) }}" class="ui-card ui-card--interactive flex items-center justify-between gap-3 p-4">
                    <span class="text-2xl font-bold text-primary">{{ $health['count'] }}</span>
                    <x-ui.badge :tone="$health['tone']">{{ $health['label'] }}</x-ui.badge>
                </a>
            @endforeach
        </div>
    </section>
    @endif

    @php($provisioningTotal = array_sum($provisioningCounts))
    @if ($provisioningTotal > 0)
        <section class="ui-alert ui-alert--warning mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-primary">{{ __('Infrastructure provisioning') }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        {{ trans_choice(':count resource is being prepared|:count resources are being prepared', $provisioningTotal, ['count' => $provisioningTotal]) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm font-medium text-ternary">
                    <a href="{{ route('servers.index', ['provisioning' => 1]) }}" class="underline">{{ __('View provisioning servers') }}</a>
                    <a href="{{ route('websites.index', ['provisioning' => 1]) }}" class="underline">{{ __('View provisioning websites') }}</a>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="ui-card p-3">
                    <span class="block text-xl font-bold text-primary">{{ $provisioningCounts['servers'] }}</span>
                    <span class="text-xs font-semibold uppercase text-secondary">{{ __('Servers') }}</span>
                </div>
                <div class="ui-card p-3">
                    <span class="block text-xl font-bold text-primary">{{ $provisioningCounts['websites'] }}</span>
                    <span class="text-xs font-semibold uppercase text-secondary">{{ __('Websites') }}</span>
                </div>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($provisioningResources as $resource)
                    @php($isServer = $resource instanceof \App\Models\Server)
                    <a
                        href="{{ $isServer ? route('servers.show', $resource) : route('websites.show', $resource) }}"
                        class="ui-card ui-card--interactive flex items-center justify-between gap-4 p-4"
                    >
                        <div>
                            <span class="block font-medium text-primary">{{ $isServer ? $resource->label : $resource->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ $isServer ? __('Server') : __('Website') }}</span>
                        </div>
                        <div class="text-right text-xs text-secondary">
                            <span class="block font-semibold uppercase">{{ str($resource->provisioning_status)->replace('_', ' ') }}</span>
                            <span class="mt-1 block">{{ $resource->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($provisioningTotal > $provisioningResources->count())
                <p class="mt-4 text-sm text-secondary">
                    {{ trans_choice(':count more resource is provisioning|:count more resources are provisioning', $provisioningTotal - $provisioningResources->count(), ['count' => $provisioningTotal - $provisioningResources->count()]) }}
                </p>
            @endif
        </section>
    @endif

    @php($activeDeploymentTotal = array_sum($activeDeploymentCounts))
    @if ($activeDeploymentTotal > 0)
        <section class="ui-alert ui-alert--info mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-primary">{{ __('Active deployments') }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        {{ trans_choice(':count deployment is in progress|:count deployments are in progress', $activeDeploymentTotal, ['count' => $activeDeploymentTotal]) }}
                    </p>
                </div>
                <a href="{{ route('builds.index', ['active' => 1]) }}" class="text-sm font-medium text-ternary underline">{{ __('View active deployments') }}</a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    \App\Models\Build::STATUS_QUEUED => __('Queued'),
                    \App\Models\Build::STATUS_DEPLOYING => __('Deploying'),
                    \App\Models\Build::STATUS_RUNNING => __('Running'),
                    \App\Models\Build::STATUS_TIMING_OUT => __('Timing out'),
                ] as $status => $label)
                    <div class="ui-card p-3">
                        <span class="block text-xl font-bold text-primary">{{ $activeDeploymentCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-secondary">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($activeDeployments as $build)
                    <a href="{{ route('builds.show', $build) }}" class="ui-card ui-card--interactive flex items-center justify-between gap-4 p-4">
                        <div>
                            <span class="block font-medium text-primary">{{ $build->repository->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">
                                {{ $build->repository->website?->name }}
                                @if ($build->repository->website?->server)
                                    &middot; {{ $build->repository->website->server->label }}
                                @endif
                            </span>
                        </div>
                        <div class="text-right text-xs text-secondary">
                            <span class="block font-semibold uppercase">{{ str($build->status)->replace('_', ' ') }}</span>
                            <span class="mt-1 block">{{ $build->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($activeDeploymentTotal > $activeDeployments->count())
                <a href="{{ route('builds.index', ['active' => 1]) }}" class="mt-4 inline-block text-sm font-medium text-ternary underline">
                    {{ trans_choice(':count more active deployment|:count more active deployments', $activeDeploymentTotal - $activeDeployments->count(), ['count' => $activeDeploymentTotal - $activeDeployments->count()]) }}
                </a>
            @endif
        </section>
    @endif

    @php($webhookDeliveryTotal = array_sum($webhookDeliveryCounts))
    @if ($webhookDeliveryTotal > 0)
        <section class="ui-alert ui-alert--info mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-primary">{{ __('Webhook deliveries') }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        {{ trans_choice(':count delivery received in the last 24 hours|:count deliveries received in the last 24 hours', $webhookDeliveryTotal, ['count' => $webhookDeliveryTotal]) }}
                    </p>
                </div>
                <a href="{{ route('activity.index', ['category' => 'deployment']) }}" class="text-sm font-medium text-ternary underline">
                    {{ __('View deployment activity') }}
                </a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-6">
                @foreach ([
                    \App\Models\RepositoryWebhookDelivery::STATUS_QUEUED => __('Queued'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_PENDING => __('Pending'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_SKIPPED => __('Skipped'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE => __('Unavailable'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED => __('Superseded'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_RECEIVED => __('Received'),
                ] as $status => $label)
                    <div class="ui-card p-3">
                        <span class="block text-xl font-bold text-primary">{{ $webhookDeliveryCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-secondary">{{ $label }}</span>
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
                            <span class="block font-medium text-primary">{{ $delivery->repository->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ __('Delivery #:id', ['id' => $delivery->id]) }}</span>
                        </div>
                        <div class="text-right text-xs text-secondary">
                            <span class="block font-semibold uppercase">{{ $delivery->status }}</span>
                            <span class="mt-1 block">{{ $delivery->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($webhookDeliveryTotal > $recentWebhookDeliveries->count())
                <p class="mt-4 text-sm text-secondary">
                    {{ trans_choice(':count more delivery is available in repository history|:count more deliveries are available in repository history', $webhookDeliveryTotal - $recentWebhookDeliveries->count(), ['count' => $webhookDeliveryTotal - $recentWebhookDeliveries->count()]) }}
                </p>
            @endif
        </section>
    @endif

    @php($activeCommandTotal = array_sum($activeCommandCounts))
    @if ($activeCommandTotal > 0)
        <section class="ui-alert ui-alert--info mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-primary">{{ __('Active server commands') }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        {{ trans_choice(':count command is active|:count commands are active', $activeCommandTotal, ['count' => $activeCommandTotal]) }}
                    </p>
                </div>
                <a href="{{ route('commands.index', ['active' => 1]) }}" class="text-sm font-medium text-ternary underline">
                    {{ __('Open Command Center') }}
                </a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                @foreach ([
                    \App\Models\ServerCommandExecution::STATUS_QUEUED => __('Queued'),
                    \App\Models\ServerCommandExecution::STATUS_RUNNING => __('Running'),
                ] as $status => $label)
                    <div class="ui-card p-3">
                        <span class="block text-xl font-bold text-primary">{{ $activeCommandCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-secondary">{{ $label }}</span>
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
                            <span class="block font-medium text-primary">{{ $execution->server->label }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ __('Command #:id', ['id' => $execution->id]) }}</span>
                        </div>
                        <div class="text-right text-xs text-secondary">
                            <span class="block font-semibold uppercase">{{ $execution->status }}</span>
                            <span class="mt-1 block">{{ $execution->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($activeCommandTotal > $activeCommands->count())
                <a href="{{ route('commands.index', ['active' => 1]) }}" class="mt-4 inline-block text-sm font-medium text-ternary underline">
                    {{ trans_choice(':count more active command is available in server history|:count more active commands are available in server history', $activeCommandTotal - $activeCommands->count(), ['count' => $activeCommandTotal - $activeCommands->count()]) }}
                </a>
            @endif
        </section>
    @endif

    @if ($communityReportCount > 0)
        <section class="ui-alert ui-alert--danger mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-primary">{{ __('Community recipe feedback') }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        {{ trans_choice(':count community report needs review|:count community reports need review', $communityReportCount, ['count' => $communityReportCount]) }}
                        &middot;
                        {{ trans_choice(':count published recipe affected|:count published recipes affected', $reportedGalleryRecipeCount, ['count' => $reportedGalleryRecipeCount]) }}
                    </p>
                </div>
                <a href="{{ route('gallery.reports.index') }}" class="text-sm font-medium text-ternary underline">
                    {{ __('Open feedback inbox') }}
                </a>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <a href="{{ route('gallery.reports.index') }}" class="ui-card ui-card--interactive p-3 text-primary">
                    <span class="block text-xl font-bold">{{ $communityReportCount }}</span>
                    <span class="text-xs font-semibold uppercase">{{ __('All needing review') }}</span>
                </a>
                <a href="{{ route('gallery.reports.index', ['reason' => 'security', 'sort' => 'priority']) }}" class="ui-card ui-card--interactive p-3 text-primary">
                    <span class="block text-xl font-bold">{{ $communityReportAttention['security'] }}</span>
                    <span class="text-xs font-semibold uppercase">{{ __('Security reports') }}</span>
                </a>
                <a href="{{ route('gallery.reports.index', ['age' => '7d', 'sort' => 'oldest']) }}" class="ui-card ui-card--interactive p-3 text-primary">
                    <span class="block text-xl font-bold">{{ $communityReportAttention['stale'] }}</span>
                    <span class="text-xs font-semibold uppercase">{{ __('Open at least 7 days') }}</span>
                </a>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($reportedGalleryRecipes as $recipe)
                    <a href="{{ route('gallery.reports.index', ['recipe' => $recipe->id]) }}" class="ui-card ui-card--interactive flex items-center justify-between gap-4 p-4">
                        <div>
                            <span class="block font-medium text-primary">{{ $recipe->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ str($recipe->category)->headline() }}</span>
                        </div>
                        <span class="text-sm font-semibold text-ternary">
                            {{ trans_choice(':count report|:count reports', $recipe->reports_count, ['count' => $recipe->reports_count]) }}
                        </span>
                    </a>
                @endforeach
            </div>

            @if ($reportedGalleryRecipeCount > $reportedGalleryRecipes->count())
                <a href="{{ route('gallery.reports.index') }}" class="mt-4 inline-block text-sm font-medium text-ternary underline">
                    {{ trans_choice(':count more reported recipe|:count more reported recipes', $reportedGalleryRecipeCount - $reportedGalleryRecipes->count(), ['count' => $reportedGalleryRecipeCount - $reportedGalleryRecipes->count()]) }}
                </a>
            @endif
        </section>
    @endif

    @if ($recipeUpdateCount > 0)
        <section class="ui-alert ui-alert--warning mb-12 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-primary">{{ __('Recipe updates') }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        {{ trans_choice(':count installed recipe has a gallery update|:count installed recipes have gallery updates', $recipeUpdateCount, ['count' => $recipeUpdateCount]) }}
                    </p>
                </div>
                <a href="{{ route('gallery.index', ['scope' => 'updates']) }}" class="text-sm font-medium text-ternary underline">
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
                                <span class="block font-medium text-primary">{{ $recipe->name }}</span>
                                <span class="mt-1 block text-sm text-secondary">
                                    {{ str($recipe->category)->headline() }} &middot; {{ __('by :author', ['author' => $recipe->user->name]) }}
                                </span>
                                <span class="mt-1 block text-xs text-secondary">
                                    {{ __('Installed as :name', ['name' => $installedRecipe->name]) }}
                                    &middot; {{ __('updated :time', ['time' => $recipe->gallery_revision_at->diffForHumans()]) }}
                                </span>
                            </div>
                            <div class="flex gap-3 text-sm font-medium">
                                <a href="{{ route('gallery.compare', ['recipe' => $recipe, 'copy' => $installedRecipe]) }}" class="text-ternary underline">
                                    {{ __('Review changes') }}
                                </a>
                                @php($recipeEditDialogId = 'recipe-edit-dialog-'.$installedRecipe->id)
                                <a
                                    href="{{ route('dashboard', ['dialog' => 'edit-recipe-'.$installedRecipe->id]) }}"
                                    data-modal-trigger="{{ $recipeEditDialogId }}"
                                    data-modal-content-url="{{ route('recipes.edit', ['recipe' => $installedRecipe, 'dialog' => 'edit-recipe-'.$installedRecipe->id, 'fragment' => 1, 'return_to' => $dashboardUrl]) }}"
                                    aria-controls="{{ $recipeEditDialogId }}"
                                    aria-expanded="{{ $dashboardRecipeEditOpen && $editingDashboardRecipe?->id === $installedRecipe->id ? 'true' : 'false' }}"
                                    class="text-ternary underline"
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
                <a href="{{ route('gallery.index', ['scope' => 'updates']) }}" class="mt-4 inline-block text-sm font-medium text-ternary underline">
                    {{ trans_choice(':count more recipe update|:count more recipe updates', $recipeUpdateCount - $recipeUpdates->count(), ['count' => $recipeUpdateCount - $recipeUpdates->count()]) }}
                </a>
            @endif
        </section>
    @endif

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
        <section>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-primary">{{ __('Recent websites') }}</h2>
                <a href="{{ route('websites.index') }}" class="text-sm text-ternary">{{ __('View all') }}</a>
            </div>

            @forelse ($recentWebsites as $website)
                <a href="{{ route('websites.show', $website) }}" class="ui-card mb-3 flex items-center justify-between p-4">
                    <div>
                        <p class="font-medium text-primary">{{ $website->name }}</p>
                        <p class="text-sm text-secondary">{{ $website->url }}</p>
                    </div>
                    <span class="text-sm text-secondary">{{ $website->server?->label ?? __('No server') }}</span>
                </a>
            @empty
                <x-lists.empty
                    :title="__('No websites yet')"
                    :description="__('Create a website to begin configuring deployments.')"
                >
                    <x-slot:button>
                        <x-ui.button
                            :href="$dashboardWebsiteCreateUrl"
                            data-modal-trigger="website-create-dialog"
                            aria-controls="website-create-dialog"
                            aria-expanded="{{ $dashboardWebsiteCreateOpen ? 'true' : 'false' }}"
                            variant="primary"
                        >{{ __('Add website') }}</x-ui.button>
                    </x-slot:button>
                </x-lists.empty>
            @endforelse
        </section>

        <section>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-primary">{{ __('Recent builds') }}</h2>
                <a href="{{ route('builds.index') }}" class="text-sm text-ternary">{{ __('View all') }}</a>
            </div>

            @forelse ($recentBuilds as $build)
                <a href="{{ route('builds.show', $build) }}" class="ui-card mb-3 flex items-center justify-between p-4">
                    <div>
                        <p class="font-medium text-primary">{{ $build->repository->name }}</p>
                        <p class="text-sm text-secondary">{{ $build->repository->website?->name }}</p>
                    </div>
                    <div class="text-right text-sm text-secondary">
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
            <h2 class="text-xl font-semibold text-primary">{{ __('Recent activity') }}</h2>
            <a href="{{ route('activity.index') }}" class="text-sm text-ternary">{{ __('View all') }}</a>
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

</x-layouts.app>
