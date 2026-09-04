<x-layouts.app>

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
        :title="$provider->name"
        :description="$provider->description"
    >
        <x-slot:buttons>
            <form method="POST" action="{{ route('providers.connection.test', $provider) }}">
                @csrf
                <button type="submit" class="button secondary">
                    {{ __('Test connection') }}
                </button>
            </form>

            <a href="{{ route('providers.edit', $provider) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Provider') }}
            </a>

            <x-dialogs.delete
                id="delete-provider"
                :route="route('providers.destroy', $provider)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this provider?')"
            ></x-dialogs.delete>

            <button type="submit" class="button primary" onclick="document.getElementById('delete-provider').showModal()">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Provider') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (session('provider_connection'))
        @php($connection = session('provider_connection'))
        <div @class([
            'my-4 rounded border p-3 text-sm',
            'border-green-300 bg-green-50 text-green-700' => $connection['successful'],
            'border-red-300 bg-red-50 text-red-700' => ! $connection['successful'],
        ])>
            {{ $connection['message'] }}
        </div>
    @endif

    <div class="my-4 flex flex-wrap items-center gap-2 rounded border border-primary bg-primary p-3 text-sm">
        <span class="font-medium text-primary">{{ __('Latest connection check:') }}</span>
        <span @class([
            'font-medium',
            'text-green-600' => $provider->connectionHealth() === \App\Models\Provider::CONNECTION_HEALTHY,
            'text-red-600' => $provider->connectionHealth() === \App\Models\Provider::CONNECTION_FAILED,
            'text-secondary' => $provider->connectionHealth() === \App\Models\Provider::CONNECTION_UNCHECKED,
        ])>
            {{ str($provider->connectionHealth())->title() }}
        </span>
        @if ($provider->connection_checked_at)
            <span class="text-secondary">{{ $provider->connection_checked_at->diffForHumans() }}</span>
        @else
            <span class="text-secondary">{{ __('Run a connection check to verify this credential.') }}</span>
        @endif
        <span class="text-secondary">&middot;</span>
        <span @class([
            'font-medium',
            'text-green-600' => $provider->connection_monitoring_enabled,
            'text-amber-700' => ! $provider->connection_monitoring_enabled,
        ])>
            {{ $provider->connection_monitoring_enabled ? __('Automatic monitoring enabled') : __('Automatic monitoring paused') }}
        </span>
    </div>

    @if ($errors->has('provider'))
        <div class="my-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            {{ $errors->first('provider') }}
        </div>
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
                <a href="{{ route('providers.connection-checks.index', $provider) }}" class="button primary">{{ __('View all connection checks') }}</a>
                @if ($connectionChecks->isNotEmpty())
                    <a href="{{ route('providers.connection-checks.export', $provider) }}" class="button primary">{{ __('Export connection history') }}</a>
                @endif
            </div>
        </div>

        @if ($connectionChecks->isEmpty())
            <div class="mt-4 rounded-lg border border-primary bg-primary p-5 text-sm text-secondary">
                {{ __('No connection checks have been recorded yet.') }}
            </div>
        @else
            <div class="mt-4 overflow-x-auto rounded-lg border border-primary">
                <table class="min-w-full divide-y divide-primary bg-primary text-sm">
                    <thead>
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Result') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Source') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Provider type') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Response') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Endpoint') }}</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold text-secondary">{{ __('Checked') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary">
                        @foreach ($connectionChecks as $check)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <span @class([
                                        'rounded-full px-2 py-1 text-xs font-semibold uppercase',
                                        'bg-green-100 text-green-700' => $check->successful,
                                        'bg-red-100 text-red-700' => ! $check->successful,
                                    ])>{{ $check->successful ? __('Healthy') : __('Failed') }}</span>
                                    @if ($check->error)
                                        <p class="mt-2 max-w-md whitespace-pre-wrap break-words text-xs text-red-700">{{ $check->error }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-primary">{{ str($check->source)->title() }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-primary">{{ str($check->provider_type)->headline() }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-primary">
                                    {{ $check->http_status ? __('HTTP :status', ['status' => $check->http_status]) : __('No status') }}
                                    <span class="block text-xs text-secondary">{{ __(':duration ms', ['duration' => $check->duration_ms]) }}</span>
                                </td>
                                <td class="max-w-md break-all px-4 py-3 font-mono text-xs text-primary">{{ $check->endpoint ?? __('Unavailable') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-secondary" title="{{ $check->checked_at }}">
                                    {{ $check->checked_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <!--
     ! ------------------------------------------------------------
     ! List attached servers or repos for this token
     ! ------------------------------------------------------------
     !-->
    <div class="py-4 grid grid-cols-3 gap-6">

        @if($provider->isSourceControl())
            <div class="col-span-3 lg:col-span-1 space-y-4">
                <div class="p-4 bg-primary rounded-lg border shadow-md border-primary">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold leading-none text-primary">
                            {{ __('Repositories') }}
                        </h3>
                        <a href="{{ route('repositories.create') }}" class="text-ternary text-xs font-semibold underline">
                            {{ __('Add Repository') }}
                        </a>
                    </div>
                    <div class="flow-root">
                        <ul role="list" class="divide-y divide-primary">
                            @forelse($repositories as $repository)
                                <a href="{{ route('repositories.show', $repository) }}" class="py-3">
                                    <div class="flex items-center space-x-4">
                                        <div class="flex-shrink-0">
                                            <x-avatar :name="$repository->name" class="h-8 w-8 rounded-full text-xs" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-primary truncate">
                                                {{ $repository->name }}
                                            </p>
                                            <p class="text-sm truncate text-secondary">
                                                {{ $repository->url }}
                                            </p>
                                        </div>
                                        <div class="inline-flex items-center text-md font-semibold text-primary">
                                            {{ $repository->created_at->diffForHumans() }}
                                        </div>
                                    </div>
                                </a>
                            @empty
                                <x-alerts.info :title="__('No Repositories using this provider')"></x-alerts.info>
                            @endforelse
                        </ul>
                        @if ($repositories->hasPages())
                            <div class="mt-4 border-t border-primary pt-4">
                                {{ $repositories->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if(str($provider->provider)->contains(['digitalocean']))
            <div class="col-span-1 space-y-4">
                <div class="p-4 bg-primary rounded-lg border shadow-md border-primary">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold leading-none text-primary">
                            {{ __('Servers') }}
                        </h3>
                        <a href="{{ route('servers.create') }}" class="text-ternary text-xs font-semibold underline">
                            {{ __('Add Server') }}
                        </a>
                    </div>
                    <div class="flow-root">
                        <ul role="list" class="divide-y divide-primary">
                            @forelse($servers as $server)
                                <a href="{{ route('servers.show', $server) }}" class="py-3">
                                    <div class="flex items-center space-x-4">
                                        <div class="flex-shrink-0">
                                            <x-avatar :name="$server->label" class="h-8 w-8 rounded-full text-xs" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-primary truncate">
                                                {{ $server->label }}
                                            </p>
                                            <p class="text-sm truncate text-secondary">
                                                #{{ $server->identifier }}
                                            </p>
                                        </div>
                                        <div class="inline-flex items-center text-md font-semibold text-primary">
                                            {{ $server->created_at->diffForHumans() }}
                                        </div>
                                    </div>
                                </a>
                            @empty
                                <x-alerts.info :title="__('No Servers using this provider')"></x-alerts.info>
                            @endforelse
                        </ul>
                        @if ($servers->hasPages())
                            <div class="mt-4 border-t border-primary pt-4">
                                {{ $servers->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

    </div>

</x-layouts.app>
