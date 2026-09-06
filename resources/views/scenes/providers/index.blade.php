<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="__('Providers')"
        :description="__('Manage infrastructure integrations and review their filtered connection state.')"
    >
        <x-slot:buttons>
            <a
                href="{{ route('providers.export', array_filter($filters, fn ($value) => $value !== null)) }}"
                class="button secondary"
            >
                {{ __('Export CSV') }}
            </a>
            <a
                href="{{ route('providers.create') }}"
                class="flex items-center bg-primary px-3 py-2 text-primary text-xs rounded-sm border border-primary"
            >
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Provider') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <form method="GET" action="{{ route('providers.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name or description') }}"
                    class="input secondary mt-1 w-full rounded-sm"
                >
            </div>
            <div>
                <label for="type" class="block text-xs font-semibold uppercase text-secondary">{{ __('Type') }}</label>
                <select id="type" name="type" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All provider types') }}</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected($filters['type'] === $type)>
                            {{ str($type)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="usage" class="block text-xs font-semibold uppercase text-secondary">{{ __('Usage') }}</label>
                <select id="usage" name="usage" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All usage states') }}</option>
                    @foreach ($usages as $usage)
                        <option value="{{ $usage }}" @selected($filters['usage'] === $usage)>
                            {{ str($usage)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="connection" class="block text-xs font-semibold uppercase text-secondary">{{ __('Connection') }}</label>
                <select id="connection" name="connection" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All connection states') }}</option>
                    @foreach ($connectionStatuses as $status)
                        <option value="{{ $status }}" @selected($filters['connection'] === $status)>
                            {{ str($status)->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('providers.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Matching providers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['total'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Providers in this filtered view.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('In use') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['in_use'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching providers with attached resources.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Unused') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['unused'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching providers ready for a resource.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Healthy connections') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['healthy'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Latest credential check succeeded.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failed connections') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['failed'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Latest credential check failed.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Unchecked connections') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['unchecked'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('No credential result is recorded yet.') }}</dd>
        </div>
    </dl>

    <!--
     ! ------------------------------------------------------------
     ! List Providers
     ! ------------------------------------------------------------
     !-->
    @if(!$providers->isEmpty())
        <div class="mt-6 overflow-x-auto">
            <table class="min-w-full divide-y divide-primary border-primary border-t border-b">
                <thead class="bg-primary border-l border-r border-primary">
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                            {{ __('Provider') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Description') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Attached resources') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Connection') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Created At') }}
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary bg-primary">
                    @foreach($providers as $provider)
                        <tr class="border-l border-r border-primary">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 shrink-0">
                                        <x-avatar :name="$provider->name" class="h-10 w-10 rounded-md text-sm" />
                                    </div>
                                    <a href="{{ route('providers.show', $provider) }}" class="ml-4">
                                        <div class="font-medium text-primary">
                                            {{ $provider->name }}
                                        </div>
                                        <div class="text-secondary">
                                            {{ $provider->provider }}
                                        </div>
                                    </a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary">
                                    {{ $provider->description }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary">
                                    {{ trans_choice(':count server|:count servers', $provider->servers_count, ['count' => $provider->servers_count]) }}
                                </div>
                                <div class="text-secondary">
                                    {{ trans_choice(':count repository|:count repositories', $provider->repositories_count, ['count' => $provider->repositories_count]) }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <div @class([
                                    'font-medium',
                                    'text-green-600' => $provider->connectionHealth() === \App\Models\Provider::CONNECTION_HEALTHY,
                                    'text-red-600' => $provider->connectionHealth() === \App\Models\Provider::CONNECTION_FAILED,
                                    'text-secondary' => $provider->connectionHealth() === \App\Models\Provider::CONNECTION_UNCHECKED,
                                ])>
                                    {{ str($provider->connectionHealth())->title() }}
                                </div>
                                @if ($provider->connection_checked_at)
                                    <div class="text-xs text-secondary">{{ $provider->connection_checked_at->diffForHumans() }}</div>
                                @endif
                                @unless ($provider->connection_monitoring_enabled)
                                    <div class="text-xs font-medium text-amber-700">{{ __('Automatic monitoring paused') }}</div>
                                @endunless
                                <div class="text-xs text-secondary">
                                    {{ trans_choice('Every :count hour|Every :count hours', intdiv($provider->connection_check_interval_minutes, 60), ['count' => intdiv($provider->connection_check_interval_minutes, 60)]) }}
                                </div>
                                <div class="text-xs text-secondary">
                                    {{ trans_choice('Alert after :count failure|Alert after :count failures', $provider->connection_failure_threshold, ['count' => $provider->connection_failure_threshold]) }}
                                    @if ($provider->connection_failure_count > 0)
                                        &middot; {{ __(':count recorded', ['count' => $provider->connection_failure_count]) }}
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary">
                                    {{ $provider->created_at->diffForHumans() }}
                                </div>
                            </td>
                            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <a href="{{ route('providers.show', $provider) }}" aria-label="{{ __('View :name', ['name' => $provider->name]) }}">
                                    <svg class="inline-block w-4 h-4 text-secondary stroke-2 mr-2">
                                        <use xlink:href="/assets/images/icons.svg#chevron-right"></use>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach

                    <!-- More people... -->
                </tbody>
            </table>
        </div>
        <div class="py-4">
            {{ $providers->links() }}
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No providers match these filters') : __('You have no providers')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('You have no providers. Click the button below to add one.')"
            >
                <x-slot:button>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <a href="{{ route('providers.index') }}" class="button primary">{{ __('Clear filters') }}</a>
                    @else
                        <a href="{{ route('providers.create') }}" class="px-3 py-2 bg-secondary border border-primary text-primary rounded-sm text-sm shadow-sm">
                            {{ __('Add Provider') }}
                        </a>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
