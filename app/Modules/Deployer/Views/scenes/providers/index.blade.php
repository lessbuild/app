<x-layouts.app>

    @php
        $providerIndexQuery = array_filter($filters, fn ($value) => $value !== null);
        $providerCreateOpen = request()->query('dialog') === 'create-provider';
        $providerCreateUrl = route('providers.index', [...$providerIndexQuery, 'dialog' => 'create-provider']);
    @endphp

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
            <x-signal.ui.button :href="route('providers.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                {{ __('Export CSV') }}
            </x-signal.ui.button>
            <x-signal.ui.button
                :href="$providerCreateUrl"
                data-modal-trigger="provider-create-dialog"
                aria-controls="provider-create-dialog"
                aria-expanded="{{ $providerCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="mr-2 h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Provider') }}
            </x-signal.ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-signal.ui.local-nav class="mt-6" :label="__('Provider sections')">
        <a href="#providers-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#provider-inventory" class="ui-local-nav__link">{{ __('Inventory') }}</a>
    </x-signal.ui.local-nav>

    @php($activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null)))

    <x-signal.ui.filter-panel
        id="providers-filters"
        class="mt-8"
        :label="__('Filter providers')"
        :open="$activeFilterCount > 0"
        :summary="$activeFilterCount > 0 ? __(':count active', ['count' => $activeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('providers.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="ui-label">{{ __('Search') }}</label>
                <x-signal.ui.input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name or description') }}"
                    class="ui-input" :restore="false" />
            </div>
            <div>
                <label for="type" class="ui-label">{{ __('Type') }}</label>
                <x-signal.ui.select id="type" name="type" class="ui-input">
                    <option value="">{{ __('All provider types') }}</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected($filters['type'] === $type)>
                            {{ str($type)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="usage" class="ui-label">{{ __('Usage') }}</label>
                <x-signal.ui.select id="usage" name="usage" class="ui-input">
                    <option value="">{{ __('All usage states') }}</option>
                    @foreach ($usages as $usage)
                        <option value="{{ $usage }}" @selected($filters['usage'] === $usage)>
                            {{ str($usage)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="connection" class="ui-label">{{ __('Connection') }}</label>
                <x-signal.ui.select id="connection" name="connection" class="ui-input">
                    <option value="">{{ __('All connection states') }}</option>
                    @foreach ($connectionStatuses as $status)
                        <option value="{{ $status }}" @selected($filters['connection'] === $status)>
                            {{ str($status)->title() }}
                        </option>
                    @endforeach
                </x-signal.ui.select>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-signal.ui.button :href="route('providers.index')" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            @endif
        </div>
        </form>
    </x-signal.ui.filter-panel>

    <x-signal.ui.insights
        id="providers-insights"
        class="mt-6 scroll-mt-24"
        :summary="trans_choice(':count matching provider|:count matching providers', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-signal.ui.stat :label="__('Matching providers')" :value="$metrics['total']" :description="__('Providers in this filtered view.')" />
            <x-signal.ui.stat :label="__('In use')" :value="$metrics['in_use']" :description="__('Matching providers with attached resources.')" />
            <x-signal.ui.stat :label="__('Unused')" :value="$metrics['unused']" :description="__('Matching providers ready for a resource.')" />
            <x-signal.ui.stat :label="__('Healthy connections')" :value="$metrics['healthy']" :description="__('Latest credential check succeeded.')" />
            <x-signal.ui.stat :label="__('Failed connections')" :value="$metrics['failed']" :description="__('Latest credential check failed.')" />
            <x-signal.ui.stat :label="__('Unchecked connections')" :value="$metrics['unchecked']" :description="__('No credential result is recorded yet.')" />
        </dl>
    </x-signal.ui.insights>

    <!--
     ! ------------------------------------------------------------
     ! List Providers
     ! ------------------------------------------------------------
     !-->
    <div id="provider-inventory" data-provider-section="inventory" class="scroll-mt-24">
    @if(!$providers->isEmpty())
        <x-signal.ui.panel class="ui-panel mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Provider inventory') }}">
            @foreach($providers as $provider)
                @php($connectionHealth = $provider->connectionHealth())
                <article data-provider-card class="group p-4 transition-colors hover:bg-surface-muted sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-avatar :name="$provider->name" class="ui-avatar-md shrink-0" />
                            <div class="min-w-0">
                                <a href="{{ route('providers.show', $provider) }}" class="ui-link break-words">
                                    {{ $provider->name }}
                                </a>
                                <p class="mt-0.5 text-sm text-muted">{{ str($provider->provider)->replace('_', ' ')->title() }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($connectionHealth === \App\Modules\Deployer\Models\Provider::CONNECTION_HEALTHY)
                                <x-signal.ui.badge tone="success">{{ str($connectionHealth)->title() }}</x-signal.ui.badge>
                            @elseif ($connectionHealth === \App\Modules\Deployer\Models\Provider::CONNECTION_FAILED)
                                <x-signal.ui.badge tone="danger">{{ str($connectionHealth)->title() }}</x-signal.ui.badge>
                            @else
                                <x-signal.ui.badge>{{ str($connectionHealth)->title() }}</x-signal.ui.badge>
                            @endif
                            <x-signal.ui.button :href="route('providers.show', $provider)" variant="secondary">
                                {{ __('View provider') }}
                            </x-signal.ui.button>
                        </div>
                    </div>

                    @if ($provider->description)
                        <p class="mt-3 text-sm leading-6 text-muted">{{ $provider->description }}</p>
                    @endif

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Attached resources') }}</dt>
                            <dd class="mt-1 text-ink">
                                {{ trans_choice(':count server|:count servers', $provider->servers_count, ['count' => $provider->servers_count]) }}
                                <span class="mt-1 block text-muted">{{ trans_choice(':count repository|:count repositories', $provider->repositories_count, ['count' => $provider->repositories_count]) }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Connection') }}</dt>
                            <dd class="mt-1 text-ink">
                                @if ($provider->connection_checked_at)
                                    {{ $provider->connection_checked_at->diffForHumans() }}
                                @else
                                    {{ __('Not checked yet') }}
                                @endif
                                @unless ($provider->connection_monitoring_enabled)
                                    <span class="mt-1 block font-medium text-warning">{{ __('Automatic monitoring paused') }}</span>
                                @endunless
                            </dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Monitoring') }}</dt>
                            <dd class="mt-1 text-ink">
                                {{ trans_choice('Every :count hour|Every :count hours', intdiv($provider->connection_check_interval_minutes, 60), ['count' => intdiv($provider->connection_check_interval_minutes, 60)]) }}
                                <span class="mt-1 block text-muted">
                                    {{ trans_choice('Alert after :count failure|Alert after :count failures', $provider->connection_failure_threshold, ['count' => $provider->connection_failure_threshold]) }}
                                    @if ($provider->connection_failure_count > 0)
                                        &middot; {{ __(':count recorded', ['count' => $provider->connection_failure_count]) }}
                                    @endif
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Created') }}</dt>
                            <dd class="mt-1 text-ink">{{ $provider->created_at->diffForHumans() }}</dd>
                        </div>
                    </dl>
                </article>
            @endforeach
        </x-signal.ui.panel>
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
                        <x-signal.ui.button :href="route('providers.index')" variant="primary">{{ __('Clear filters') }}</x-signal.ui.button>
                    @else
                        <x-signal.ui.button
                            :href="$providerCreateUrl"
                            data-modal-trigger="provider-create-dialog"
                            aria-controls="provider-create-dialog"
                            aria-expanded="{{ $providerCreateOpen ? 'true' : 'false' }}"
                            variant="secondary"
                        >{{ __('Add Provider') }}</x-signal.ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
    </div>

    <x-scenes.providers.create-dialog :open="$providerCreateOpen" />
</x-layouts.app>
