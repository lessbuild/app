@php($attentionTotal = array_sum($attentionCounts))

<section @class([
    'ui-panel mb-12 p-5',
    'ui-panel--danger' => $attentionTotal > 0,
    'ui-panel--success' => $attentionTotal === 0,
]) aria-labelledby="dashboard-attention-title">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 id="dashboard-attention-title" @class([
                'mt-2 text-xl font-extrabold tracking-tight text-ink',
            ])>
                {{ $attentionTotal > 0 ? __('Needs attention') : __('No active failures') }}
            </h2>
            <p @class([
                'mt-1 text-sm text-muted',
            ])>
                @if ($attentionTotal > 0)
                    {{ trans_choice(':count active issue|:count active issues', $attentionTotal, ['count' => $attentionTotal]) }}
                @else
                    {{ __('No unhealthy websites, provisioning failures, failed latest deployments, or provider connection failures.') }}
                @endif
            </p>
        </div>
        @if ($attentionTotal > 0)
            <a href="{{ route('notifications.index') }}" class="ui-link text-sm">
                {{ __('View notifications') }}
            </a>
        @endif
    </div>

    @if ($attentionTotal > 0)
        <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-4">
            <div>
                <h3 class="ui-eyebrow mb-2">
                    {{ __('Websites') }} ({{ $attentionCounts['websites'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionWebsites as $website)
                        <a href="{{ route('websites.show', $website) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-bold text-ink">{{ $website->name }}</span>
                            <span class="text-sm text-muted">
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
                        <p class="text-sm text-muted">{{ __('No website failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['websites'] > $attentionWebsites->count())
                        <a href="{{ route('websites.index', ['attention' => 1]) }}" class="ui-link block text-sm">
                            {{ trans_choice(':count more website|:count more websites', $attentionCounts['websites'] - $attentionWebsites->count(), ['count' => $attentionCounts['websites'] - $attentionWebsites->count()]) }}
                        </a>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="ui-eyebrow mb-2">
                    {{ __('Servers') }} ({{ $attentionCounts['servers'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionServers as $server)
                        <a href="{{ route('servers.show', $server) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-bold text-ink">{{ $server->label }}</span>
                            <span class="text-sm text-muted">{{ __('Provisioning failed') }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-muted">{{ __('No server failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['servers'] > $attentionServers->count())
                        <a href="{{ route('servers.index', ['status' => \App\Models\Server::STATUS_FAILED]) }}" class="ui-link block text-sm">
                            {{ trans_choice(':count more server|:count more servers', $attentionCounts['servers'] - $attentionServers->count(), ['count' => $attentionCounts['servers'] - $attentionServers->count()]) }}
                        </a>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="ui-eyebrow mb-2">
                    {{ __('Deployments') }} ({{ $attentionCounts['deployments'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionRepositories as $repository)
                        <a href="{{ route('builds.show', $repository->latestBuild) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-bold text-ink">{{ $repository->name }}</span>
                            <span class="text-sm text-muted">
                                {{ __('Latest deployment failed') }}
                                @if ($repository->website)
                                    &middot; {{ $repository->website->name }}
                                @endif
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-muted">{{ __('No deployment failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['deployments'] > $attentionRepositories->count())
                        <a href="{{ route('builds.index', ['status' => \App\Models\Build::STATUS_FAILED, 'latest' => 1]) }}" class="ui-link block text-sm">
                            {{ trans_choice(':count more deployment|:count more deployments', $attentionCounts['deployments'] - $attentionRepositories->count(), ['count' => $attentionCounts['deployments'] - $attentionRepositories->count()]) }}
                        </a>
                    @endif
                </div>
            </div>

            <div>
                <h3 class="ui-eyebrow mb-2">
                    {{ __('Providers') }} ({{ $attentionCounts['providers'] }})
                </h3>
                <div class="space-y-2">
                    @forelse ($attentionProviders as $provider)
                        <a href="{{ route('providers.show', $provider) }}" class="ui-card ui-card--interactive block p-3">
                            <span class="block font-bold text-ink">{{ $provider->name }}</span>
                            <span class="text-sm text-muted">
                                {{ __('Connection failed') }}
                                @if ($provider->connection_checked_at)
                                    &middot; {{ $provider->connection_checked_at->diffForHumans() }}
                                @endif
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-muted">{{ __('No provider failures.') }}</p>
                    @endforelse
                    @if ($attentionCounts['providers'] > $attentionProviders->count())
                        <a href="{{ route('providers.index', ['connection' => \App\Models\Provider::CONNECTION_FAILED]) }}" class="ui-link block text-sm">
                            {{ trans_choice(':count more provider|:count more providers', $attentionCounts['providers'] - $attentionProviders->count(), ['count' => $attentionCounts['providers'] - $attentionProviders->count()]) }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</section>
