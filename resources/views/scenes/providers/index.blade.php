<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        eyebrow="{{ __('Integrations') }}"
        icon="cloud"
        :title="__('Providers')"
        :description="__('Manage infrastructure integrations and review their filtered connection state.')"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('providers.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                {{ __('Export CSV') }}
            </x-ui.button>
            <x-ui.button :href="route('providers.create')" variant="primary">
                <svg class="mr-2 h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Provider') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @php($activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null)))

    <x-ui.filter-panel
        id="providers-filters"
        class="mt-8"
        :label="__('Filter providers')"
        :open="$activeFilterCount > 0"
        :summary="$activeFilterCount > 0 ? __(':count active', ['count' => $activeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('providers.index') }}">
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
                    class="input secondary mt-1 w-full rounded-lg"
                >
            </div>
            <div>
                <label for="type" class="block text-xs font-semibold uppercase text-secondary">{{ __('Type') }}</label>
                <select id="type" name="type" class="input secondary mt-1 w-full rounded-lg">
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
                <select id="usage" name="usage" class="input secondary mt-1 w-full rounded-lg">
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
                <select id="connection" name="connection" class="input secondary mt-1 w-full rounded-lg">
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
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-ui.button :href="route('providers.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        </form>
    </x-ui.filter-panel>

    <x-ui.insights
        id="providers-insights"
        class="mt-6"
        :summary="trans_choice(':count matching provider|:count matching providers', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat :label="__('Matching providers')" :value="$metrics['total']" :description="__('Providers in this filtered view.')" />
            <x-ui.stat :label="__('In use')" :value="$metrics['in_use']" :description="__('Matching providers with attached resources.')" />
            <x-ui.stat :label="__('Unused')" :value="$metrics['unused']" :description="__('Matching providers ready for a resource.')" />
            <x-ui.stat :label="__('Healthy connections')" :value="$metrics['healthy']" :description="__('Latest credential check succeeded.')" />
            <x-ui.stat :label="__('Failed connections')" :value="$metrics['failed']" :description="__('Latest credential check failed.')" />
            <x-ui.stat :label="__('Unchecked connections')" :value="$metrics['unchecked']" :description="__('No credential result is recorded yet.')" />
        </dl>
    </x-ui.insights>

    <!--
     ! ------------------------------------------------------------
     ! List Providers
     ! ------------------------------------------------------------
     !-->
    @if(!$providers->isEmpty())
        <div class="ui-card mt-6 divide-y divide-primary overflow-hidden" aria-label="{{ __('Provider inventory') }}">
            @foreach($providers as $provider)
                @php($connectionHealth = $provider->connectionHealth())
                <article data-provider-card class="p-4 sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-avatar :name="$provider->name" class="h-10 w-10 shrink-0 rounded-md text-sm" />
                            <div class="min-w-0">
                                <a href="{{ route('providers.show', $provider) }}" class="font-semibold text-primary hover:underline">
                                    {{ $provider->name }}
                                </a>
                                <p class="text-sm text-secondary">{{ $provider->provider }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($connectionHealth === \App\Models\Provider::CONNECTION_HEALTHY)
                                <x-ui.badge tone="success">{{ str($connectionHealth)->title() }}</x-ui.badge>
                            @elseif ($connectionHealth === \App\Models\Provider::CONNECTION_FAILED)
                                <x-ui.badge tone="danger">{{ str($connectionHealth)->title() }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ str($connectionHealth)->title() }}</x-ui.badge>
                            @endif
                            <x-ui.button :href="route('providers.show', $provider)" variant="secondary">
                                {{ __('View provider') }}
                            </x-ui.button>
                        </div>
                    </div>

                    @if ($provider->description)
                        <p class="mt-3 text-sm text-secondary">{{ $provider->description }}</p>
                    @endif

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Attached resources') }}</dt>
                            <dd class="mt-1 text-primary">
                                {{ trans_choice(':count server|:count servers', $provider->servers_count, ['count' => $provider->servers_count]) }}
                                <span class="mt-1 block text-secondary">{{ trans_choice(':count repository|:count repositories', $provider->repositories_count, ['count' => $provider->repositories_count]) }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Connection') }}</dt>
                            <dd class="mt-1 text-primary">
                                @if ($provider->connection_checked_at)
                                    {{ $provider->connection_checked_at->diffForHumans() }}
                                @else
                                    {{ __('Not checked yet') }}
                                @endif
                                @unless ($provider->connection_monitoring_enabled)
                                    <span class="mt-1 block font-medium text-amber-700">{{ __('Automatic monitoring paused') }}</span>
                                @endunless
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Monitoring') }}</dt>
                            <dd class="mt-1 text-primary">
                                {{ trans_choice('Every :count hour|Every :count hours', intdiv($provider->connection_check_interval_minutes, 60), ['count' => intdiv($provider->connection_check_interval_minutes, 60)]) }}
                                <span class="mt-1 block text-secondary">
                                    {{ trans_choice('Alert after :count failure|Alert after :count failures', $provider->connection_failure_threshold, ['count' => $provider->connection_failure_threshold]) }}
                                    @if ($provider->connection_failure_count > 0)
                                        &middot; {{ __(':count recorded', ['count' => $provider->connection_failure_count]) }}
                                    @endif
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Created') }}</dt>
                            <dd class="mt-1 text-primary">{{ $provider->created_at->diffForHumans() }}</dd>
                        </div>
                    </dl>
                </article>
            @endforeach
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
                        <x-ui.button :href="route('providers.index')" variant="primary">{{ __('Clear filters') }}</x-ui.button>
                    @else
                        <x-ui.button :href="route('providers.create')" variant="secondary">{{ __('Add Provider') }}</x-ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
