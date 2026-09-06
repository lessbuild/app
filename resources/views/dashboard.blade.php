<x-layouts.app>
    <header class="mb-6 flex flex-col gap-5 rounded-xl border border-primary bg-primary px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6" aria-labelledby="dashboard-title">
        <div class="min-w-0">
            <p class="text-sm text-secondary">{{ __('Welcome back, :name', ['name' => auth()->user()->name]) }}</p>
            <h1 id="dashboard-title" class="mt-1 break-words text-2xl font-bold text-primary">{{ auth()->user()->currentOrganization?->name ?: __('Dashboard') }}</h1>
            <p class="mt-1 text-sm text-secondary">{{ __('Your infrastructure. Your next deployment. One clear view.') }}</p>
        </div>
        <nav class="flex shrink-0 flex-wrap gap-2" aria-label="{{ __('Dashboard quick actions') }}">
            <a href="{{ route('servers.create') }}" class="button secondary">
                {{ __('Create server') }}
            </a>
            <a href="{{ route('websites.create') }}" class="button primary">
                {{ __('Add website') }}
            </a>
        </nav>
    </header>

    <details class="mb-6 rounded-xl border border-primary bg-primary p-4">
        <summary class="cursor-pointer font-bold text-primary">{{ __('Customize dashboard') }}</summary>
        <form method="POST" action="{{ route('dashboard.preferences.update') }}" class="mt-4 flex flex-wrap items-end gap-4">@csrf @method('PATCH')
            @foreach(['stats' => __('Resource totals'), 'setup' => __('Setup progress'), 'status' => __('Platform status'), 'providers' => __('Provider health')] as $widget => $label)
                <label class="flex items-center gap-2 text-sm text-secondary"><input type="checkbox" name="widgets[]" value="{{ $widget }}" @checked(in_array($widget, $dashboardWidgets, true))><span>{{ $label }}</span></label>
            @endforeach
            <button type="submit" class="button primary">{{ __('Save layout') }}</button>
        </form>
    </details>

    @if(in_array('stats', $dashboardWidgets, true))
    <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 -mx-3 mb-12">
        <x-panel.stats icon="link" :title="$stats['websites']" :description="__('Websites')" />
        <x-panel.stats icon="cloud" :title="$stats['servers']" :description="__('Servers')" />
        <x-panel.stats icon="cloud-upload" :title="$stats['builds']" :description="__('Builds')" />
        <x-panel.stats icon="code" :title="$stats['repositories']" :description="__('Repositories')" />
    </div>
    @endif

    <section class="mb-12" aria-labelledby="operations-overview-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Last 14 days') }}</p>
                <h2 id="operations-overview-title" class="mt-1 text-xl font-semibold text-primary">{{ __('Operational overview') }}</h2>
            </div>
            <a href="{{ route('observability.index') }}" class="text-sm font-bold text-ternary underline">{{ __('Open observability') }}</a>
        </div>
        <div class="grid gap-4 xl:grid-cols-[1fr_1fr_.8fr]">
            <article class="rounded-2xl border border-primary bg-primary p-5" aria-labelledby="deployment-volume-title">
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

            <article class="rounded-2xl border border-primary bg-primary p-5" aria-labelledby="health-reliability-title">
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

            <article class="rounded-2xl border border-primary bg-primary p-5" aria-labelledby="plan-capacity-title">
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
    </section>

    @php
        $onboardingSteps = [
            'provider' => ['title' => __('Connect a provider'), 'description' => __('Add cloud credentials for server provisioning.'), 'createUrl' => route('providers.create'), 'reviewUrl' => route('providers.index')],
            'server' => ['title' => __('Provision a server'), 'description' => __('Create the application server that will run your sites.'), 'createUrl' => route('servers.create'), 'reviewUrl' => route('servers.index')],
            'website' => ['title' => __('Add a website'), 'description' => __('Choose a domain and place it on an active server.'), 'createUrl' => route('websites.create'), 'reviewUrl' => route('websites.index')],
            'repository' => ['title' => __('Connect a repository'), 'description' => __('Attach the Git source and deployment settings.'), 'createUrl' => route('repositories.create'), 'reviewUrl' => route('repositories.index')],
            'deployment' => ['title' => __('Complete a deployment'), 'description' => __('Ship a revision and verify the release succeeds.'), 'createUrl' => route('repositories.index'), 'reviewUrl' => route('builds.index')],
        ];
        $onboardingCompleted = collect($onboarding)->filter()->count();
        $currentOnboardingStep = collect($onboarding)->search(fn (bool $complete): bool => ! $complete);
    @endphp
    @if (in_array('setup', $dashboardWidgets, true) && $onboardingCompleted < count($onboardingSteps))
        <section class="mb-12 overflow-hidden rounded-xl border border-blue-300 bg-primary shadow-sm" aria-labelledby="setup-progress-title">
            <div class="border-b border-primary bg-blue-50 p-5 sm:p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-blue-700">{{ __('Workspace setup') }}</p>
                        <h2 id="setup-progress-title" class="mt-1 text-2xl font-semibold text-blue-950">{{ __('Get to your first healthy deployment') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-blue-800">{{ __('Follow the dependency order once, then manage every resource from the same workspace.') }}</p>
                    </div>
                    <p class="text-sm font-bold text-blue-900">{{ __(':complete of :total complete', ['complete' => $onboardingCompleted, 'total' => count($onboardingSteps)]) }}</p>
                </div>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-blue-100" role="progressbar" aria-label="{{ __('Workspace setup progress') }}" aria-valuemin="0" aria-valuemax="{{ count($onboardingSteps) }}" aria-valuenow="{{ $onboardingCompleted }}">
                    <div class="h-full rounded-full bg-blue-600 transition-all" style="width: {{ ($onboardingCompleted / count($onboardingSteps)) * 100 }}%"></div>
                </div>
            </div>
            <ol class="grid gap-px bg-secondary md:grid-cols-2 xl:grid-cols-5">
                @foreach ($onboardingSteps as $key => $step)
                    @php($complete = $onboarding[$key])
                    @php($current = $currentOnboardingStep === $key)
                    <li @class([
                        'flex min-h-52 flex-col bg-primary p-5',
                        'ring-2 ring-inset ring-blue-500' => $current,
                    ])>
                        <div class="flex items-center justify-between gap-3">
                            <span @class([
                                'flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold',
                                'bg-green-100 text-green-800' => $complete,
                                'bg-blue-100 text-blue-800' => $current,
                                'bg-secondary text-secondary' => ! $complete && ! $current,
                            ])>{{ $complete ? '✓' : $loop->iteration }}</span>
                            <span @class([
                                'text-xs font-bold uppercase tracking-wide',
                                'text-green-700' => $complete,
                                'text-blue-700' => $current,
                                'text-secondary' => ! $complete && ! $current,
                            ])>{{ $complete ? __('Complete') : ($current ? __('Current step') : __('Upcoming')) }}</span>
                        </div>
                        <h3 class="mt-4 font-semibold text-primary">{{ $step['title'] }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-6 text-secondary">{{ $step['description'] }}</p>
                        @if ($complete)
                            <a href="{{ $step['reviewUrl'] }}" class="mt-4 text-sm font-semibold text-ternary underline">{{ __('Review') }}</a>
                        @elseif ($current)
                            <a href="{{ $step['createUrl'] }}" class="button primary mt-4 justify-center">{{ $key === 'deployment' ? __('Deploy repository') : __('Continue setup') }}</a>
                        @else
                            <span class="mt-4 text-xs font-medium text-secondary">{{ __('Available after the previous step') }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @php($healthOperational = $canManageSystemHealth ? $systemHealth['passed'] : $platformStatus['operational'])
    @if(in_array('status', $dashboardWidgets, true))
    <section @class([
        'mb-12 rounded-lg border p-5',
        'border-green-300 bg-green-50' => $healthOperational,
        'border-red-300 bg-red-50' => ! $healthOperational,
    ]) aria-labelledby="dashboard-system-health">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase text-secondary">{{ __('Platform status') }}</p>
                <h2 id="dashboard-system-health" @class([
                    'mt-1 text-xl font-semibold',
                    'text-green-800' => $healthOperational,
                    'text-red-800' => ! $healthOperational,
                ])>
                    {{ $healthOperational ? __('System operational') : __('System health needs attention') }}
                </h2>
                @if ($canManageSystemHealth)
                    <p @class(['mt-1 text-sm', 'text-green-800' => $healthOperational, 'text-red-800' => ! $healthOperational])>
                        {{ trans_choice(':passed of :total check passed|:passed of :total checks passed', $systemHealth['total'], ['passed' => $systemHealth['passed_count'], 'total' => $systemHealth['total']]) }}
                    </p>
                    @if (! $systemHealth['passed'])
                        <p class="mt-2 text-sm text-red-800">{{ __('Failing: :checks', ['checks' => implode(', ', $systemHealth['failed_checks'])]) }}</p>
                    @endif
                @else
                    <p @class(['mt-1 text-sm', 'text-green-800' => $healthOperational, 'text-red-800' => ! $healthOperational])>
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
    <section class="mb-12 rounded-lg border border-primary bg-primary p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-primary">{{ __('Provider credential health') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ __('Latest automated and manual provider connection results.') }}</p>
            </div>
            <a href="{{ route('providers.index') }}" class="text-sm font-medium text-ternary underline">{{ __('Manage providers') }}</a>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['status' => \App\Models\Provider::CONNECTION_HEALTHY, 'label' => __('Healthy'), 'count' => $providerHealthCounts['healthy'], 'classes' => 'border-green-300 bg-green-50 text-green-800'],
                ['status' => \App\Models\Provider::CONNECTION_FAILED, 'label' => __('Failed'), 'count' => $providerHealthCounts['failed'], 'classes' => 'border-red-300 bg-red-50 text-red-800'],
                ['status' => \App\Models\Provider::CONNECTION_UNCHECKED, 'label' => __('Unchecked'), 'count' => $providerHealthCounts['unchecked'], 'classes' => 'border-primary bg-secondary text-primary'],
            ] as $health)
                <a href="{{ route('providers.index', ['connection' => $health['status']]) }}" class="rounded border p-4 {{ $health['classes'] }}">
                    <span class="block text-2xl font-bold">{{ $health['count'] }}</span>
                    <span class="text-sm font-medium">{{ $health['label'] }}</span>
                </a>
            @endforeach
        </div>
    </section>
    @endif

    @php($provisioningTotal = array_sum($provisioningCounts))
    @if ($provisioningTotal > 0)
        <section class="mb-12 rounded-lg border border-amber-300 bg-amber-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-amber-900">{{ __('Infrastructure provisioning') }}</h2>
                    <p class="mt-1 text-sm text-amber-800">
                        {{ trans_choice(':count resource is being prepared|:count resources are being prepared', $provisioningTotal, ['count' => $provisioningTotal]) }}
                    </p>
                </div>
                <div class="flex gap-3 text-sm font-medium text-amber-800">
                    <a href="{{ route('servers.index', ['provisioning' => 1]) }}" class="underline">{{ __('View provisioning servers') }}</a>
                    <a href="{{ route('websites.index', ['provisioning' => 1]) }}" class="underline">{{ __('View provisioning websites') }}</a>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded border border-amber-200 bg-white p-3">
                    <span class="block text-xl font-bold text-amber-900">{{ $provisioningCounts['servers'] }}</span>
                    <span class="text-xs font-semibold uppercase text-amber-700">{{ __('Servers') }}</span>
                </div>
                <div class="rounded border border-amber-200 bg-white p-3">
                    <span class="block text-xl font-bold text-amber-900">{{ $provisioningCounts['websites'] }}</span>
                    <span class="text-xs font-semibold uppercase text-amber-700">{{ __('Websites') }}</span>
                </div>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($provisioningResources as $resource)
                    @php($isServer = $resource instanceof \App\Models\Server)
                    <a
                        href="{{ $isServer ? route('servers.show', $resource) : route('websites.show', $resource) }}"
                        class="flex items-center justify-between gap-4 rounded border border-amber-200 bg-white p-4"
                    >
                        <div>
                            <span class="block font-medium text-primary">{{ $isServer ? $resource->label : $resource->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ $isServer ? __('Server') : __('Website') }}</span>
                        </div>
                        <div class="text-right text-xs text-amber-800">
                            <span class="block font-semibold uppercase">{{ str($resource->provisioning_status)->replace('_', ' ') }}</span>
                            <span class="mt-1 block">{{ $resource->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($provisioningTotal > $provisioningResources->count())
                <p class="mt-4 text-sm text-amber-800">
                    {{ trans_choice(':count more resource is provisioning|:count more resources are provisioning', $provisioningTotal - $provisioningResources->count(), ['count' => $provisioningTotal - $provisioningResources->count()]) }}
                </p>
            @endif
        </section>
    @endif

    @php($activeDeploymentTotal = array_sum($activeDeploymentCounts))
    @if ($activeDeploymentTotal > 0)
        <section class="mb-12 rounded-lg border border-blue-300 bg-blue-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-blue-900">{{ __('Active deployments') }}</h2>
                    <p class="mt-1 text-sm text-blue-800">
                        {{ trans_choice(':count deployment is in progress|:count deployments are in progress', $activeDeploymentTotal, ['count' => $activeDeploymentTotal]) }}
                    </p>
                </div>
                <a href="{{ route('builds.index', ['active' => 1]) }}" class="text-sm font-medium text-blue-800 underline">{{ __('View active deployments') }}</a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    \App\Models\Build::STATUS_QUEUED => __('Queued'),
                    \App\Models\Build::STATUS_DEPLOYING => __('Deploying'),
                    \App\Models\Build::STATUS_RUNNING => __('Running'),
                    \App\Models\Build::STATUS_TIMING_OUT => __('Timing out'),
                ] as $status => $label)
                    <div class="rounded border border-blue-200 bg-white p-3">
                        <span class="block text-xl font-bold text-blue-900">{{ $activeDeploymentCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-blue-700">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($activeDeployments as $build)
                    <a href="{{ route('builds.show', $build) }}" class="flex items-center justify-between gap-4 rounded border border-blue-200 bg-white p-4">
                        <div>
                            <span class="block font-medium text-primary">{{ $build->repository->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">
                                {{ $build->repository->website?->name }}
                                @if ($build->repository->website?->server)
                                    &middot; {{ $build->repository->website->server->label }}
                                @endif
                            </span>
                        </div>
                        <div class="text-right text-xs text-blue-800">
                            <span class="block font-semibold uppercase">{{ str($build->status)->replace('_', ' ') }}</span>
                            <span class="mt-1 block">{{ $build->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($activeDeploymentTotal > $activeDeployments->count())
                <a href="{{ route('builds.index', ['active' => 1]) }}" class="mt-4 inline-block text-sm font-medium text-blue-800 underline">
                    {{ trans_choice(':count more active deployment|:count more active deployments', $activeDeploymentTotal - $activeDeployments->count(), ['count' => $activeDeploymentTotal - $activeDeployments->count()]) }}
                </a>
            @endif
        </section>
    @endif

    @php($webhookDeliveryTotal = array_sum($webhookDeliveryCounts))
    @if ($webhookDeliveryTotal > 0)
        <section class="mb-12 rounded-lg border border-cyan-300 bg-cyan-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-cyan-900">{{ __('Webhook deliveries') }}</h2>
                    <p class="mt-1 text-sm text-cyan-800">
                        {{ trans_choice(':count delivery received in the last 24 hours|:count deliveries received in the last 24 hours', $webhookDeliveryTotal, ['count' => $webhookDeliveryTotal]) }}
                    </p>
                </div>
                <a href="{{ route('activity.index', ['category' => 'deployment']) }}" class="text-sm font-medium text-cyan-800 underline">
                    {{ __('View deployment activity') }}
                </a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                @foreach ([
                    \App\Models\RepositoryWebhookDelivery::STATUS_QUEUED => __('Queued'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_PENDING => __('Pending'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE => __('Unavailable'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED => __('Superseded'),
                    \App\Models\RepositoryWebhookDelivery::STATUS_RECEIVED => __('Received'),
                ] as $status => $label)
                    <div class="rounded border border-cyan-200 bg-white p-3">
                        <span class="block text-xl font-bold text-cyan-900">{{ $webhookDeliveryCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-cyan-700">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($recentWebhookDeliveries as $delivery)
                    <a
                        href="{{ route('repositories.show', ['repository' => $delivery->repository, 'delivery_status' => $delivery->status]) }}#webhook-deliveries"
                        class="flex items-center justify-between gap-4 rounded border border-cyan-200 bg-white p-4"
                    >
                        <div>
                            <span class="block font-medium text-primary">{{ $delivery->repository->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ __('Delivery #:id', ['id' => $delivery->id]) }}</span>
                        </div>
                        <div class="text-right text-xs text-cyan-800">
                            <span class="block font-semibold uppercase">{{ $delivery->status }}</span>
                            <span class="mt-1 block">{{ $delivery->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($webhookDeliveryTotal > $recentWebhookDeliveries->count())
                <p class="mt-4 text-sm text-cyan-800">
                    {{ trans_choice(':count more delivery is available in repository history|:count more deliveries are available in repository history', $webhookDeliveryTotal - $recentWebhookDeliveries->count(), ['count' => $webhookDeliveryTotal - $recentWebhookDeliveries->count()]) }}
                </p>
            @endif
        </section>
    @endif

    @php($activeCommandTotal = array_sum($activeCommandCounts))
    @if ($activeCommandTotal > 0)
        <section class="mb-12 rounded-lg border border-violet-300 bg-violet-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-violet-900">{{ __('Active server commands') }}</h2>
                    <p class="mt-1 text-sm text-violet-800">
                        {{ trans_choice(':count command is active|:count commands are active', $activeCommandTotal, ['count' => $activeCommandTotal]) }}
                    </p>
                </div>
                <a href="{{ route('commands.index', ['active' => 1]) }}" class="text-sm font-medium text-violet-800 underline">
                    {{ __('Open Command Center') }}
                </a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                @foreach ([
                    \App\Models\ServerCommandExecution::STATUS_QUEUED => __('Queued'),
                    \App\Models\ServerCommandExecution::STATUS_RUNNING => __('Running'),
                ] as $status => $label)
                    <div class="rounded border border-violet-200 bg-white p-3">
                        <span class="block text-xl font-bold text-violet-900">{{ $activeCommandCounts[$status] }}</span>
                        <span class="text-xs font-semibold uppercase text-violet-700">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($activeCommands as $execution)
                    <a
                        href="{{ route('servers.commands.index', ['server' => $execution->server, 'status' => $execution->status]) }}"
                        class="flex items-center justify-between gap-4 rounded border border-violet-200 bg-white p-4"
                    >
                        <div>
                            <span class="block font-medium text-primary">{{ $execution->server->label }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ __('Command #:id', ['id' => $execution->id]) }}</span>
                        </div>
                        <div class="text-right text-xs text-violet-800">
                            <span class="block font-semibold uppercase">{{ $execution->status }}</span>
                            <span class="mt-1 block">{{ $execution->created_at->diffForHumans() }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($activeCommandTotal > $activeCommands->count())
                <a href="{{ route('commands.index', ['active' => 1]) }}" class="mt-4 inline-block text-sm font-medium text-violet-800 underline">
                    {{ trans_choice(':count more active command is available in server history|:count more active commands are available in server history', $activeCommandTotal - $activeCommands->count(), ['count' => $activeCommandTotal - $activeCommands->count()]) }}
                </a>
            @endif
        </section>
    @endif

    @if ($communityReportCount > 0)
        <section class="mb-12 rounded-lg border border-rose-300 bg-rose-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-rose-900">{{ __('Community recipe feedback') }}</h2>
                    <p class="mt-1 text-sm text-rose-800">
                        {{ trans_choice(':count community report needs review|:count community reports need review', $communityReportCount, ['count' => $communityReportCount]) }}
                        &middot;
                        {{ trans_choice(':count published recipe affected|:count published recipes affected', $reportedGalleryRecipeCount, ['count' => $reportedGalleryRecipeCount]) }}
                    </p>
                </div>
                <a href="{{ route('gallery.reports.index') }}" class="text-sm font-medium text-rose-800 underline">
                    {{ __('Open feedback inbox') }}
                </a>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <a href="{{ route('gallery.reports.index') }}" class="rounded border border-rose-200 bg-white p-3 text-rose-900">
                    <span class="block text-xl font-bold">{{ $communityReportCount }}</span>
                    <span class="text-xs font-semibold uppercase">{{ __('All needing review') }}</span>
                </a>
                <a href="{{ route('gallery.reports.index', ['reason' => 'security', 'sort' => 'priority']) }}" class="rounded border border-red-300 bg-red-50 p-3 text-red-900">
                    <span class="block text-xl font-bold">{{ $communityReportAttention['security'] }}</span>
                    <span class="text-xs font-semibold uppercase">{{ __('Security reports') }}</span>
                </a>
                <a href="{{ route('gallery.reports.index', ['age' => '7d', 'sort' => 'oldest']) }}" class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-900">
                    <span class="block text-xl font-bold">{{ $communityReportAttention['stale'] }}</span>
                    <span class="text-xs font-semibold uppercase">{{ __('Open at least 7 days') }}</span>
                </a>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($reportedGalleryRecipes as $recipe)
                    <a href="{{ route('gallery.reports.index', ['recipe' => $recipe->id]) }}" class="flex items-center justify-between gap-4 rounded border border-rose-200 bg-white p-4">
                        <div>
                            <span class="block font-medium text-primary">{{ $recipe->name }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ str($recipe->category)->headline() }}</span>
                        </div>
                        <span class="text-sm font-semibold text-rose-800">
                            {{ trans_choice(':count report|:count reports', $recipe->reports_count, ['count' => $recipe->reports_count]) }}
                        </span>
                    </a>
                @endforeach
            </div>

            @if ($reportedGalleryRecipeCount > $reportedGalleryRecipes->count())
                <a href="{{ route('gallery.reports.index') }}" class="mt-4 inline-block text-sm font-medium text-rose-800 underline">
                    {{ trans_choice(':count more reported recipe|:count more reported recipes', $reportedGalleryRecipeCount - $reportedGalleryRecipes->count(), ['count' => $reportedGalleryRecipeCount - $reportedGalleryRecipes->count()]) }}
                </a>
            @endif
        </section>
    @endif

    @if ($recipeUpdateCount > 0)
        <section class="mb-12 rounded-lg border border-orange-300 bg-orange-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-orange-900">{{ __('Recipe updates') }}</h2>
                    <p class="mt-1 text-sm text-orange-800">
                        {{ trans_choice(':count installed recipe has a gallery update|:count installed recipes have gallery updates', $recipeUpdateCount, ['count' => $recipeUpdateCount]) }}
                    </p>
                </div>
                <a href="{{ route('gallery.index', ['scope' => 'updates']) }}" class="text-sm font-medium text-orange-800 underline">
                    {{ __('View all updates') }}
                </a>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-2">
                @foreach ($recipeUpdates as $recipe)
                    @php($installedRecipe = $recipe->installs->first(fn ($copy) => $copy->hasGalleryUpdate($recipe)))
                    @continue($installedRecipe === null)
                    <div class="rounded border border-orange-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <span class="block font-medium text-primary">{{ $recipe->name }}</span>
                                <span class="mt-1 block text-sm text-secondary">
                                    {{ str($recipe->category)->headline() }} &middot; {{ __('by :author', ['author' => $recipe->user->name]) }}
                                </span>
                                <span class="mt-1 block text-xs text-orange-800">
                                    {{ __('Installed as :name', ['name' => $installedRecipe->name]) }}
                                    &middot; {{ __('updated :time', ['time' => $recipe->gallery_revision_at->diffForHumans()]) }}
                                </span>
                            </div>
                            <div class="flex gap-3 text-sm font-medium">
                                <a href="{{ route('gallery.compare', ['recipe' => $recipe, 'copy' => $installedRecipe]) }}" class="text-orange-800 underline">
                                    {{ __('Review changes') }}
                                </a>
                                <a href="{{ route('recipes.edit', $installedRecipe) }}" class="text-ternary underline">
                                    {{ __('Edit copy') }}
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($recipeUpdateCount > $recipeUpdates->count())
                <a href="{{ route('gallery.index', ['scope' => 'updates']) }}" class="mt-4 inline-block text-sm font-medium text-orange-800 underline">
                    {{ trans_choice(':count more recipe update|:count more recipe updates', $recipeUpdateCount - $recipeUpdates->count(), ['count' => $recipeUpdateCount - $recipeUpdates->count()]) }}
                </a>
            @endif
        </section>
    @endif

    @php($attentionTotal = array_sum($attentionCounts))
    <section @class([
        'mb-12 rounded-lg border p-5',
        'border-red-300 bg-red-50' => $attentionTotal > 0,
        'border-green-300 bg-green-50' => $attentionTotal === 0,
    ])>
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 @class([
                    'text-xl font-semibold',
                    'text-red-800' => $attentionTotal > 0,
                    'text-green-800' => $attentionTotal === 0,
                ])>
                    {{ $attentionTotal > 0 ? __('Needs attention') : __('No active failures') }}
                </h2>
                <p @class([
                    'mt-1 text-sm',
                    'text-red-700' => $attentionTotal > 0,
                    'text-green-700' => $attentionTotal === 0,
                ])>
                    @if ($attentionTotal > 0)
                        {{ trans_choice(':count active issue|:count active issues', $attentionTotal, ['count' => $attentionTotal]) }}
                    @else
                        {{ __('No unhealthy websites, provisioning failures, failed latest deployments, or provider connection failures.') }}
                    @endif
                </p>
            </div>
            @if ($attentionTotal > 0)
                <a href="{{ route('notifications.index') }}" class="text-sm font-medium text-red-700 underline">
                    {{ __('View notifications') }}
                </a>
            @endif
        </div>

        @if ($attentionTotal > 0)
            <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-4">
                <div>
                    <h3 class="mb-2 text-sm font-semibold uppercase text-red-800">
                        {{ __('Websites') }} ({{ $attentionCounts['websites'] }})
                    </h3>
                    <div class="space-y-2">
                        @forelse ($attentionWebsites as $website)
                            <a href="{{ route('websites.show', $website) }}" class="block rounded border border-red-200 bg-white p-3">
                                <span class="block font-medium text-primary">{{ $website->name }}</span>
                                <span class="text-sm text-red-700">
                                    @if ($website->provisioning_status === \App\Models\Website::STATUS_FAILED)
                                        {{ __('Provisioning failed') }}
                                    @endif
                                    @if ($website->provisioning_status === \App\Models\Website::STATUS_FAILED
                                        && $website->health_status === \App\Models\Website::HEALTH_UNHEALTHY)
                                        &middot;
                                    @endif
                                    @if ($website->health_status === \App\Models\Website::HEALTH_UNHEALTHY)
                                        {{ __('Health check failing') }}
                                    @endif
                                </span>
                            </a>
                        @empty
                            <p class="text-sm text-red-700">{{ __('No website failures.') }}</p>
                        @endforelse
                        @if ($attentionCounts['websites'] > $attentionWebsites->count())
                            <a href="{{ route('websites.index', ['attention' => 1]) }}" class="block text-sm font-medium text-red-700 underline">
                                {{ trans_choice(':count more website|:count more websites', $attentionCounts['websites'] - $attentionWebsites->count(), ['count' => $attentionCounts['websites'] - $attentionWebsites->count()]) }}
                            </a>
                        @endif
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold uppercase text-red-800">
                        {{ __('Servers') }} ({{ $attentionCounts['servers'] }})
                    </h3>
                    <div class="space-y-2">
                        @forelse ($attentionServers as $server)
                            <a href="{{ route('servers.show', $server) }}" class="block rounded border border-red-200 bg-white p-3">
                                <span class="block font-medium text-primary">{{ $server->label }}</span>
                                <span class="text-sm text-red-700">{{ __('Provisioning failed') }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-red-700">{{ __('No server failures.') }}</p>
                        @endforelse
                        @if ($attentionCounts['servers'] > $attentionServers->count())
                            <a href="{{ route('servers.index', ['status' => \App\Models\Server::STATUS_FAILED]) }}" class="block text-sm font-medium text-red-700 underline">
                                {{ trans_choice(':count more server|:count more servers', $attentionCounts['servers'] - $attentionServers->count(), ['count' => $attentionCounts['servers'] - $attentionServers->count()]) }}
                            </a>
                        @endif
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold uppercase text-red-800">
                        {{ __('Deployments') }} ({{ $attentionCounts['deployments'] }})
                    </h3>
                    <div class="space-y-2">
                        @forelse ($attentionRepositories as $repository)
                            <a href="{{ route('builds.show', $repository->latestBuild) }}" class="block rounded border border-red-200 bg-white p-3">
                                <span class="block font-medium text-primary">{{ $repository->name }}</span>
                                <span class="text-sm text-red-700">
                                    {{ __('Latest deployment failed') }}
                                    @if ($repository->website)
                                        &middot; {{ $repository->website->name }}
                                    @endif
                                </span>
                            </a>
                        @empty
                            <p class="text-sm text-red-700">{{ __('No deployment failures.') }}</p>
                        @endforelse
                        @if ($attentionCounts['deployments'] > $attentionRepositories->count())
                            <a href="{{ route('builds.index', ['status' => \App\Models\Build::STATUS_FAILED, 'latest' => 1]) }}" class="block text-sm font-medium text-red-700 underline">
                                {{ trans_choice(':count more deployment|:count more deployments', $attentionCounts['deployments'] - $attentionRepositories->count(), ['count' => $attentionCounts['deployments'] - $attentionRepositories->count()]) }}
                            </a>
                        @endif
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold uppercase text-red-800">
                        {{ __('Providers') }} ({{ $attentionCounts['providers'] }})
                    </h3>
                    <div class="space-y-2">
                        @forelse ($attentionProviders as $provider)
                            <a href="{{ route('providers.show', $provider) }}" class="block rounded border border-red-200 bg-white p-3">
                                <span class="block font-medium text-primary">{{ $provider->name }}</span>
                                <span class="text-sm text-red-700">
                                    {{ __('Connection failed') }}
                                    @if ($provider->connection_checked_at)
                                        &middot; {{ $provider->connection_checked_at->diffForHumans() }}
                                    @endif
                                </span>
                            </a>
                        @empty
                            <p class="text-sm text-red-700">{{ __('No provider failures.') }}</p>
                        @endforelse
                        @if ($attentionCounts['providers'] > $attentionProviders->count())
                            <a href="{{ route('providers.index', ['connection' => \App\Models\Provider::CONNECTION_FAILED]) }}" class="block text-sm font-medium text-red-700 underline">
                                {{ trans_choice(':count more provider|:count more providers', $attentionCounts['providers'] - $attentionProviders->count(), ['count' => $attentionCounts['providers'] - $attentionProviders->count()]) }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </section>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
        <section>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-primary">{{ __('Recent websites') }}</h2>
                <a href="{{ route('websites.index') }}" class="text-sm text-ternary">{{ __('View all') }}</a>
            </div>

            @forelse ($recentWebsites as $website)
                <a href="{{ route('websites.show', $website) }}" class="mb-3 flex items-center justify-between rounded-lg border border-primary bg-primary p-4">
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
                        <a href="{{ route('websites.create') }}" class="button primary">{{ __('Add website') }}</a>
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
                <a href="{{ route('builds.show', $build) }}" class="mb-3 flex items-center justify-between rounded-lg border border-primary bg-primary p-4">
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
</x-layouts.app>
