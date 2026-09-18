@php($attentionTotal = array_sum($attentionCounts))

<section @class([
    'ui-alert mb-12 p-5',
    'ui-alert--danger' => $attentionTotal > 0,
    'ui-alert--success' => $attentionTotal === 0,
]) aria-labelledby="dashboard-attention-title">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 id="dashboard-attention-title" @class([
                'text-xl font-semibold',
                'text-primary',
            ])>
                {{ $attentionTotal > 0 ? __('Needs attention') : __('No active failures') }}
            </h2>
            <p @class([
                'mt-1 text-sm',
                'text-secondary',
            ])>
                @if ($attentionTotal > 0)
                    {{ trans_choice(':count active issue|:count active issues', $attentionTotal, ['count' => $attentionTotal]) }}
                @else
                    {{ __('No unhealthy websites, provisioning failures, failed latest deployments, or provider connection failures.') }}
                @endif
            </p>
        </div>
        @if ($attentionTotal > 0)
            <a href="{{ route('notifications.index') }}" class="text-sm font-medium text-ternary underline">
                {{ __('View notifications') }}
            </a>
        @endif
    </div>

    @if ($attentionTotal > 0)
        <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-4">
            <div>
                <h3 class="mb-2 text-sm font-semibold uppercase text-primary">
                    {{ __('Websites') }} ({{ $attentionCounts['websites'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionWebsites as $website)
                        <a href="{{ route('websites.show', $website) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-medium text-primary">{{ $website->name }}</span>
                            <span class="text-sm text-secondary">
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
                        <p class="text-sm text-secondary">{{ __('No website failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['websites'] > $attentionWebsites->count())
                        <a href="{{ route('websites.index', ['attention' => 1]) }}" class="block text-sm font-medium text-ternary underline">
                            {{ trans_choice(':count more website|:count more websites', $attentionCounts['websites'] - $attentionWebsites->count(), ['count' => $attentionCounts['websites'] - $attentionWebsites->count()]) }}
                        </a>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-semibold uppercase text-primary">
                    {{ __('Servers') }} ({{ $attentionCounts['servers'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionServers as $server)
                        <a href="{{ route('servers.show', $server) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-medium text-primary">{{ $server->label }}</span>
                            <span class="text-sm text-secondary">{{ __('Provisioning failed') }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-secondary">{{ __('No server failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['servers'] > $attentionServers->count())
                        <a href="{{ route('servers.index', ['status' => \App\Models\Server::STATUS_FAILED]) }}" class="block text-sm font-medium text-ternary underline">
                            {{ trans_choice(':count more server|:count more servers', $attentionCounts['servers'] - $attentionServers->count(), ['count' => $attentionCounts['servers'] - $attentionServers->count()]) }}
                        </a>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-semibold uppercase text-primary">
                    {{ __('Deployments') }} ({{ $attentionCounts['deployments'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionRepositories as $repository)
                        <a href="{{ route('builds.show', $repository->latestBuild) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-medium text-primary">{{ $repository->name }}</span>
                            <span class="text-sm text-secondary">
                                {{ __('Latest deployment failed') }}
                                @if ($repository->website)
                                    &middot; {{ $repository->website->name }}
                                @endif
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-secondary">{{ __('No deployment failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['deployments'] > $attentionRepositories->count())
                        <a href="{{ route('builds.index', ['status' => \App\Models\Build::STATUS_FAILED, 'latest' => 1]) }}" class="block text-sm font-medium text-ternary underline">
                            {{ trans_choice(':count more deployment|:count more deployments', $attentionCounts['deployments'] - $attentionRepositories->count(), ['count' => $attentionCounts['deployments'] - $attentionRepositories->count()]) }}
                        </a>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-semibold uppercase text-primary">
                    {{ __('Providers') }} ({{ $attentionCounts['providers'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionProviders as $provider)
                        <a href="{{ route('providers.show', $provider) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-medium text-primary">{{ $provider->name }}</span>
                            <span class="text-sm text-secondary">
                                {{ __('Connection failed') }}
                                @if ($provider->connection_checked_at)
                                    &middot; {{ $provider->connection_checked_at->diffForHumans() }}
                                @endif
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-secondary">{{ __('No provider failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['providers'] > $attentionProviders->count())
                        <a href="{{ route('providers.index', ['connection' => \App\Models\Provider::CONNECTION_FAILED]) }}" class="block text-sm font-medium text-ternary underline">
                            {{ trans_choice(':count more provider|:count more providers', $attentionCounts['providers'] - $attentionProviders->count(), ['count' => $attentionCounts['providers'] - $attentionProviders->count()]) }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</section>
