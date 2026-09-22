<x-layouts.app>

    @php
        $providerEditOpen = request()->query('dialog') === 'edit-provider';
        $providerConnectionChecksOpen = request()->query('dialog') === 'provider-connection-checks';
        $providerEditUrl = route('providers.show', ['provider' => $provider, 'dialog' => 'edit-provider']);
        $providerPageUrl = request()->fullUrlWithoutQuery('dialog');
        $providerEditContentUrl = route('providers.edit', ['provider' => $provider, 'dialog' => 'edit-provider', 'fragment' => 1, 'return_to' => $providerPageUrl]);
        $providerConnectionChecksUrl = (string) \Illuminate\Support\Uri::of($providerPageUrl)->withQuery(['dialog' => 'provider-connection-checks']);
        $providerConnectionChecksContentUrl = route('providers.connection-checks.index', [
            'provider' => $provider,
            'fragment' => 'provider-connection-checks',
        ]);
        $repositoryCreateUrl = (string) \Illuminate\Support\Uri::of($providerPageUrl)->withQuery(['dialog' => 'create-repository']);
        $repositoryCreateContentUrl = route('dialogs.create', ['resource' => 'repository', 'return_to' => $providerPageUrl]);
        $serverCreateUrl = (string) \Illuminate\Support\Uri::of($providerPageUrl)->withQuery(['dialog' => 'create-server']);
        $serverCreateContentUrl = route('dialogs.create', ['resource' => 'server', 'return_to' => $providerPageUrl]);
        $repositoryCreateOpen = request()->query('dialog') === 'create-repository';
        $serverCreateOpen = request()->query('dialog') === 'create-server';
        $connectionHealth = $provider->connectionHealth();
    @endphp

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Providers')"
        :route="route('providers.index')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        eyebrow="{{ __('Provider integration') }}"
        icon="cloud"
        :title="$provider->name"
        :description="$provider->description"
    >
        <x-slot:buttons>
            @if ($provider->isSourceControl())
                <x-ui.button :href="route('builds.index', ['provider_id' => $provider->id])" variant="secondary">
                    {{ __('Deployment history') }}
                </x-ui.button>
            @endif

            <form method="POST" action="{{ route('providers.connection.test', $provider) }}" aria-label="{{ __('Provider connection actions') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">
                    {{ __('Test connection') }}
                </x-ui.button>
            </form>

            <x-ui.button
                :href="$providerEditUrl"
                data-modal-trigger="provider-edit-dialog"
                data-modal-content-url="{{ $providerEditContentUrl }}"
                aria-controls="provider-edit-dialog"
                aria-expanded="{{ $providerEditOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Provider') }}
            </x-ui.button>

            <x-dialogs.delete
                id="delete-provider"
                :route="route('providers.destroy', $provider)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this provider?')"
            ></x-dialogs.delete>

            <x-ui.button type="button" variant="danger" data-modal-trigger="delete-provider" aria-controls="delete-provider" aria-expanded="false">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Provider') }}
            </x-ui.button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('provider_connection'))
        @php($connection = session('provider_connection'))
        <x-ui.alert :tone="$connection['successful'] ? 'success' : 'danger'" class="ui-panel my-6 border-l-4">
            {{ $connection['message'] }}
        </x-ui.alert>
    @endif

    <section class="ui-panel mt-6 p-5 sm:p-6" aria-labelledby="provider-connection-overview-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ __('Credential health') }}</p>
                <h2 id="provider-connection-overview-heading" class="mt-2 text-xl font-extrabold tracking-tight text-ink">
                    {{ __('Connection overview') }}
                </h2>
                <p class="mt-1 text-sm text-muted">
                    {{ __('A quiet summary of the latest credential check and its monitoring policy.') }}
                </p>
            </div>
            @if ($connectionHealth === \App\Models\Provider::CONNECTION_HEALTHY)
                <x-ui.badge tone="success">{{ str($connectionHealth)->title() }}</x-ui.badge>
            @elseif ($connectionHealth === \App\Models\Provider::CONNECTION_FAILED)
                <x-ui.badge tone="danger">{{ str($connectionHealth)->title() }}</x-ui.badge>
            @else
                <x-ui.badge>{{ str($connectionHealth)->title() }}</x-ui.badge>
            @endif
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div @class([
                'ui-card border-l-4 p-4',
                'border-success' => $connectionHealth === \App\Models\Provider::CONNECTION_HEALTHY,
                'border-danger' => $connectionHealth === \App\Models\Provider::CONNECTION_FAILED,
                'border-line' => ! in_array($connectionHealth, [
                    \App\Models\Provider::CONNECTION_HEALTHY,
                    \App\Models\Provider::CONNECTION_FAILED,
                ], true),
            ])>
                <p class="ui-eyebrow text-[0.65rem]">{{ __('Confirmed connection status:') }}</p>
                <p class="mt-2 font-bold text-ink">{{ str($connectionHealth)->title() }}</p>
                @if ($provider->connection_checked_at)
                    <p class="mt-1 text-xs text-muted">{{ $provider->connection_checked_at->diffForHumans() }}</p>
                @else
                    <p class="mt-1 text-xs text-muted">{{ __('Run a connection check to verify this credential.') }}</p>
                @endif
            </div>
            <div class="ui-card p-4">
                <p class="ui-eyebrow text-[0.65rem]">{{ __('Monitoring') }}</p>
                <p class="mt-2 font-bold {{ $provider->connection_monitoring_enabled ? 'text-success' : 'text-warning' }}">
                    {{ $provider->connection_monitoring_enabled ? __('Automatic monitoring enabled') : __('Automatic monitoring paused') }}
                </p>
                <p class="mt-1 text-xs text-muted">
                    {{ trans_choice('Every :count hour|Every :count hours', intdiv($provider->connection_check_interval_minutes, 60), ['count' => intdiv($provider->connection_check_interval_minutes, 60)]) }}
                </p>
            </div>
            <div class="ui-card p-4">
                <p class="ui-eyebrow text-[0.65rem]">{{ __('Failure confirmation') }}</p>
                <p class="mt-2 font-bold text-ink">
                    {{ trans_choice('after :count consecutive failure|after :count consecutive failures', $provider->connection_failure_threshold, ['count' => $provider->connection_failure_threshold]) }}
                </p>
                @if ($provider->connection_failure_count > 0)
                    <p class="mt-1 text-xs text-muted">
                        {{ trans_choice(':count failure recorded|:count failures recorded', $provider->connection_failure_count, ['count' => $provider->connection_failure_count]) }}
                    </p>
                @endif
            </div>
            <div class="ui-card p-4">
                <p class="ui-eyebrow text-[0.65rem]">{{ __('Credential safety') }}</p>
                <p class="mt-2 font-bold text-ink">{{ __('Encrypted at rest') }}</p>
                <p class="mt-1 text-xs text-muted">{{ __('Secrets are excluded from retained check evidence.') }}</p>
            </div>
        </div>
    </section>

    @if ($errors->has('provider'))
        <x-ui.alert tone="danger" class="ui-panel my-4 border-l-4">
            {{ $errors->first('provider') }}
        </x-ui.alert>
    @endif

    <x-ui.insights
        id="provider-overview-insights"
        class="mt-6"
        :summary="__('Credential and attached-resource coverage')"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat :label="__('Provider type')" :value="str($provider->provider)->replace('_', ' ')->title()" :description="__('The external service backing this connection.')" />
            <x-ui.stat :label="__('Attached repositories')" :value="$repositories->total()" :description="__('Source-control resources using this provider.')" />
            <x-ui.stat :label="__('Attached servers')" :value="$servers->total()" :description="__('Infrastructure resources using this provider.')" />
            <x-ui.stat :label="__('Retained checks')" :value="$connectionMetrics['total']" :description="__('Recent credential observations kept for this provider.')" />
        </dl>
    </x-ui.insights>

    <section class="ui-panel mt-8 p-5 sm:p-6" aria-labelledby="connection-history-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Connection timeline') }}</p>
                <h2 id="connection-history-heading" class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Recent connection checks') }}</h2>
                <p class="mt-1 text-sm text-muted">
                    {{ __('Accepted manual and automatic results are retained for the latest 100 checks. This page shows the newest 20 without credentials or response bodies.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button
                    :href="route('providers.connection-checks.index', $provider)"
                    data-modal-trigger="provider-connection-checks-dialog"
                    data-modal-content-url="{{ $providerConnectionChecksContentUrl }}"
                    data-modal-history-url="{{ $providerConnectionChecksUrl }}"
                    aria-controls="provider-connection-checks-dialog"
                    aria-expanded="{{ $providerConnectionChecksOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('View all connection checks') }}</x-ui.button>
                @if ($connectionChecks->isNotEmpty())
                    <x-ui.button :href="route('providers.connection-checks.export', $provider)" variant="secondary">{{ __('Export connection history') }}</x-ui.button>
                @endif
            </div>
        </div>

        <x-ui.insights
            id="provider-health-insights"
            class="mt-4"
            :summary="trans_choice(':count retained check|:count retained checks', $connectionMetrics['total'], ['count' => $connectionMetrics['total']])"
        >
            <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.stat :label="__('Retained checks')" :value="$connectionMetrics['total']" :description="__('Newest :limit maximum', ['limit' => \App\Models\ProviderConnectionCheck::MAX_PER_PROVIDER])" />
                <x-ui.stat :label="__('Observed connection success')" :value="$connectionMetrics['success_rate'] !== null ? $connectionMetrics['success_rate'].'%' : __('Not available')" :description="trans_choice(':count successful check|:count successful checks', $connectionMetrics['successful'], ['count' => $connectionMetrics['successful']])" />
                <x-ui.stat :label="__('Median successful response')" :value="$connectionMetrics['median_successful_duration_ms'] !== null ? $connectionMetrics['median_successful_duration_ms'].' ms' : __('Not recorded')" :description="__('Failed timings are excluded.')" />
                <x-ui.stat :label="__('Current failure streak')" :value="$connectionMetrics['failure_streak']" :description="trans_choice(':count consecutive failed check|:count consecutive failed checks', $connectionMetrics['failure_streak'], ['count' => $connectionMetrics['failure_streak']])" />
            </dl>
        </x-ui.insights>
        <p class="mt-3 text-xs text-muted">
            {{ __('These figures summarize retained observations and are not an SLA or a guarantee that the credential is currently valid.') }}
        </p>

        @if ($connectionChecks->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('No connection checks have been recorded yet.')" />
        @else
            <details id="provider-connection-history" class="group ui-card mt-4 overflow-hidden" @if ($connectionMetrics['failure_streak'] > 0) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 font-bold text-ink [&::-webkit-details-marker]:hidden">
                    <span>{{ __('Latest check results') }}</span>
                    <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
                </summary>
                <div class="ui-timeline space-y-3 border-t border-line p-4 sm:p-5">
                    @foreach ($connectionChecks as $check)
                        <div class="ui-timeline-item ui-card">
                            @include('scenes.providers._connection-check-card', ['check' => $check])
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </section>

    <!--
     ! ------------------------------------------------------------
     ! List attached servers or repos for this token
     ! ------------------------------------------------------------
     !-->
    <section class="ui-panel mt-8 p-5 sm:p-6" aria-labelledby="provider-resources-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Workspace resources') }}</p>
                <h2 id="provider-resources-heading" class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Attached resources') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Resources using this provider appear here with direct paths to their operational pages.') }}</p>
            </div>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">

        @if($provider->isSourceControl())
            <div class="ui-card p-4 sm:p-5">
                <div class="flex min-w-0 items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <h3 class="truncate font-extrabold text-ink">{{ __('Repositories') }}</h3>
                        <x-ui.badge data-provider-resource-count="repositories">{{ $repositories->total() }}</x-ui.badge>
                    </div>
                    <x-ui.button
                        :href="$repositoryCreateUrl"
                        data-modal-trigger="repository-create-dialog"
                        data-modal-content-url="{{ $repositoryCreateContentUrl }}"
                        aria-controls="repository-create-dialog"
                        aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}"
                        variant="ghost"
                        class="ui-btn-sm shrink-0"
                    >{{ __('Add Repository') }}</x-ui.button>
                </div>
                <ul role="list" class="mt-4 grid gap-3">
                    @forelse($repositories as $repository)
                        <li>
                            <a href="{{ route('repositories.show', $repository) }}" class="ui-card ui-card--interactive flex min-w-0 items-center gap-3 p-3">
                                <x-avatar :name="$repository->name" class="ui-avatar ui-avatar-md rounded-md text-xs" />
                                <span class="min-w-0 flex-1">
                                    <span class="ui-link block truncate text-sm">{{ $repository->name }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-muted">{{ $repository->url }}</span>
                                </span>
                                <span class="hidden shrink-0 text-xs font-semibold text-muted sm:block">{{ $repository->created_at->diffForHumans() }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="pt-3">
                            <x-ui.alert tone="info" role="status" class="border-l-4">{{ __('No Repositories using this provider') }}</x-ui.alert>
                        </li>
                    @endforelse
                </ul>
                @if ($repositories->hasPages())
                    <div class="mt-4 border-t border-line pt-4">{{ $repositories->links() }}</div>
                @endif
            </div>
        @endif

        @if(str($provider->provider)->contains(['digitalocean']))
            <div class="ui-card p-4 sm:p-5">
                <div class="flex min-w-0 items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <h3 class="truncate font-extrabold text-ink">{{ __('Servers') }}</h3>
                        <x-ui.badge data-provider-resource-count="servers">{{ $servers->total() }}</x-ui.badge>
                    </div>
                    <x-ui.button
                        :href="$serverCreateUrl"
                        data-modal-trigger="server-create-dialog"
                        data-modal-content-url="{{ $serverCreateContentUrl }}"
                        aria-controls="server-create-dialog"
                        aria-expanded="{{ $serverCreateOpen ? 'true' : 'false' }}"
                        variant="ghost"
                        class="ui-btn-sm shrink-0"
                    >{{ __('Add Server') }}</x-ui.button>
                </div>
                <ul role="list" class="mt-4 grid gap-3">
                    @forelse($servers as $server)
                        <li>
                            <a href="{{ route('servers.show', $server) }}" class="ui-card ui-card--interactive flex min-w-0 items-center gap-3 p-3">
                                <x-avatar :name="$server->label" class="ui-avatar ui-avatar-md rounded-md text-xs" />
                                <span class="min-w-0 flex-1">
                                    <span class="ui-link block truncate text-sm">{{ $server->label }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-muted">#{{ $server->identifier }}</span>
                                </span>
                                <span class="hidden shrink-0 text-xs font-semibold text-muted sm:block">{{ $server->created_at->diffForHumans() }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="pt-3">
                            <x-ui.alert tone="info" role="status" class="border-l-4">{{ __('No Servers using this provider') }}</x-ui.alert>
                        </li>
                    @endforelse
                </ul>
                @if ($servers->hasPages())
                    <div class="mt-4 border-t border-line pt-4">{{ $servers->links() }}</div>
                @endif
            </div>
        @endif

        </div>
    </section>

    <x-scenes.providers.edit-dialog :provider="$provider" :open="$providerEditOpen" />

    <x-dialogs.modal
        id="provider-connection-checks-dialog"
        :title="__('Connection check history')"
        :description="__('Review retained credential-check evidence without leaving this provider.')"
        :open="$providerConnectionChecksOpen"
        body-class="p-0"
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading connection check history…') }}</p>
        </div>
    </x-dialogs.modal>
</x-layouts.app>
