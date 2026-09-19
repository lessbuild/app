<x-layouts.app>

    @php
        $providerEditOpen = request()->query('dialog') === 'edit-provider';
        $providerEditUrl = route('providers.show', ['provider' => $provider, 'dialog' => 'edit-provider']);
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

            <form method="POST" action="{{ route('providers.connection.test', $provider) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">
                    {{ __('Test connection') }}
                </x-ui.button>
            </form>

            <x-ui.button
                :href="$providerEditUrl"
                data-modal-trigger="provider-edit-dialog"
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

            <button type="button" class="button button--danger" onclick="document.getElementById('delete-provider').showModal()">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Provider') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('provider_connection'))
        @php($connection = session('provider_connection'))
        <x-ui.alert :tone="$connection['successful'] ? 'success' : 'danger'" class="my-4">
            {{ $connection['message'] }}
        </x-ui.alert>
    @endif

    <x-ui.card class="my-4 p-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
        <span class="font-medium text-primary">{{ __('Confirmed connection status:') }}</span>
        @if ($provider->connectionHealth() === \App\Models\Provider::CONNECTION_HEALTHY)
            <x-ui.badge tone="success">{{ str($provider->connectionHealth())->title() }}</x-ui.badge>
        @elseif ($provider->connectionHealth() === \App\Models\Provider::CONNECTION_FAILED)
            <x-ui.badge tone="danger">{{ str($provider->connectionHealth())->title() }}</x-ui.badge>
        @else
            <x-ui.badge>{{ str($provider->connectionHealth())->title() }}</x-ui.badge>
        @endif
        @if ($provider->connection_checked_at)
            <span class="text-secondary">{{ $provider->connection_checked_at->diffForHumans() }}</span>
        @else
            <span class="text-secondary">{{ __('Run a connection check to verify this credential.') }}</span>
        @endif
        <span class="text-secondary" aria-hidden="true">&middot;</span>
        <span class="font-medium {{ $provider->connection_monitoring_enabled ? 'text-green-600' : 'text-amber-700' }}">
            {{ $provider->connection_monitoring_enabled ? __('Automatic monitoring enabled') : __('Automatic monitoring paused') }}
        </span>
        <span class="text-secondary">
            ({{ trans_choice('every :count hour|every :count hours', intdiv($provider->connection_check_interval_minutes, 60), ['count' => intdiv($provider->connection_check_interval_minutes, 60)]) }})
        </span>
        <span class="text-secondary" aria-hidden="true">&middot;</span>
        <span class="text-primary">{{ __('Failure confirmation') }}</span>
        <span class="text-secondary">
            {{ trans_choice('after :count consecutive failure|after :count consecutive failures', $provider->connection_failure_threshold, ['count' => $provider->connection_failure_threshold]) }}
        </span>
        @if ($provider->connection_failure_count > 0)
            <span class="text-secondary">
                ({{ trans_choice(':count failure recorded|:count failures recorded', $provider->connection_failure_count, ['count' => $provider->connection_failure_count]) }})
            </span>
        @endif
        </div>
    </x-ui.card>

    @if ($errors->has('provider'))
        <x-ui.alert tone="danger" class="my-4">
            {{ $errors->first('provider') }}
        </x-ui.alert>
    @endif

    <section class="mt-8" aria-labelledby="connection-history-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="connection-history-heading" class="text-2xl font-bold text-primary">{{ __('Recent connection checks') }}</h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Accepted manual and automatic results are retained for the latest 100 checks. This page shows the newest 20 without credentials or response bodies.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button :href="route('providers.connection-checks.index', $provider)" variant="secondary">{{ __('View all connection checks') }}</x-ui.button>
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
        <p class="mt-3 text-xs text-secondary">
            {{ __('These figures summarize retained observations and are not an SLA or a guarantee that the credential is currently valid.') }}
        </p>

        @if ($connectionChecks->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('No connection checks have been recorded yet.')" />
        @else
            <details id="provider-connection-history" class="group ui-card mt-4 overflow-hidden" @if ($connectionMetrics['failure_streak'] > 0) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 font-bold text-primary [&::-webkit-details-marker]:hidden">
                    <span>{{ __('Latest check results') }}</span>
                    <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
                </summary>
                <div class="divide-y divide-primary border-t border-primary">
                    @foreach ($connectionChecks as $check)
                        @include('scenes.providers._connection-check-card', ['check' => $check])
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
    <div class="mt-8 grid gap-6 lg:grid-cols-2">

        @if($provider->isSourceControl())
            <x-ui.card class="p-5">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-lg font-bold text-primary">{{ __('Repositories') }}</h3>
                <x-ui.button :href="route('repositories.index', ['dialog' => 'create-repository'])" variant="ghost">{{ __('Add Repository') }}</x-ui.button>
                </div>
                <ul role="list" class="mt-4 divide-y divide-primary">
                    @forelse($repositories as $repository)
                        <li>
                            <a href="{{ route('repositories.show', $repository) }}" class="flex items-center gap-4 py-3 hover:bg-secondary">
                                <x-avatar :name="$repository->name" class="h-8 w-8 shrink-0 rounded-full text-xs" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-primary">{{ $repository->name }}</span>
                                    <span class="block truncate text-sm text-secondary">{{ $repository->url }}</span>
                                </span>
                                <span class="shrink-0 text-xs font-semibold text-secondary">{{ $repository->created_at->diffForHumans() }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="pt-3">
                            <x-ui.alert tone="info" role="status">{{ __('No Repositories using this provider') }}</x-ui.alert>
                        </li>
                    @endforelse
                </ul>
                @if ($repositories->hasPages())
                    <div class="mt-4 border-t border-primary pt-4">{{ $repositories->links() }}</div>
                @endif
            </x-ui.card>
        @endif

        @if(str($provider->provider)->contains(['digitalocean']))
            <x-ui.card class="p-5">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-lg font-bold text-primary">{{ __('Servers') }}</h3>
                    <x-ui.button :href="route('servers.index', ['dialog' => 'create-server'])" variant="ghost">{{ __('Add Server') }}</x-ui.button>
                </div>
                <ul role="list" class="mt-4 divide-y divide-primary">
                    @forelse($servers as $server)
                        <li>
                            <a href="{{ route('servers.show', $server) }}" class="flex items-center gap-4 py-3 hover:bg-secondary">
                                <x-avatar :name="$server->label" class="h-8 w-8 shrink-0 rounded-full text-xs" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-primary">{{ $server->label }}</span>
                                    <span class="block truncate text-sm text-secondary">#{{ $server->identifier }}</span>
                                </span>
                                <span class="shrink-0 text-xs font-semibold text-secondary">{{ $server->created_at->diffForHumans() }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="pt-3">
                            <x-ui.alert tone="info" role="status">{{ __('No Servers using this provider') }}</x-ui.alert>
                        </li>
                    @endforelse
                </ul>
                @if ($servers->hasPages())
                    <div class="mt-4 border-t border-primary pt-4">{{ $servers->links() }}</div>
                @endif
            </x-ui.card>
        @endif

    </div>

    @if ($providerEditOpen)
        <x-scenes.providers.edit-dialog :provider="$provider" :open="$providerEditOpen" />
    @endif
</x-layouts.app>
