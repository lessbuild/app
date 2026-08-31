<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Dashboard')"
        :description="__('A quick view of your infrastructure and latest deployments.')"
    >
        <x-slot:buttons>
            <a href="{{ route('servers.create') }}" class="button primary">
                {{ __('Create server') }}
            </a>
            <a href="{{ route('websites.create') }}" class="button primary">
                {{ __('Add website') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 -mx-3 mb-12">
        <x-panel.stats icon="link" :title="$stats['websites']" :description="__('Websites')" />
        <x-panel.stats icon="cloud" :title="$stats['servers']" :description="__('Servers')" />
        <x-panel.stats icon="cloud-upload" :title="$stats['builds']" :description="__('Builds')" />
        <x-panel.stats icon="code" :title="$stats['repositories']" :description="__('Repositories')" />
    </div>

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
                <a href="{{ route('builds.index') }}" class="text-sm font-medium text-blue-800 underline">{{ __('View build history') }}</a>
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
                                    &middot; {{ $build->repository->website->server->name }}
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
                <a href="{{ route('builds.index') }}" class="mt-4 inline-block text-sm font-medium text-blue-800 underline">
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
                <a href="{{ route('activity.index', ['category' => 'command']) }}" class="text-sm font-medium text-violet-800 underline">
                    {{ __('View command activity') }}
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
                            <span class="block font-medium text-primary">{{ $execution->server->name }}</span>
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
                <p class="mt-4 text-sm text-violet-800">
                    {{ trans_choice(':count more active command is available in server history|:count more active commands are available in server history', $activeCommandTotal - $activeCommands->count(), ['count' => $activeCommandTotal - $activeCommands->count()]) }}
                </p>
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
                                <span class="block font-medium text-primary">{{ $server->name }}</span>
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
                    <span class="text-sm text-secondary">{{ $website->server?->name ?? __('No server') }}</span>
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
