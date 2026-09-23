<x-layouts.app>

    @php
        $websiteCreateOpen = request()->query('dialog') === 'create-website';
        $websiteIndexQuery = array_filter($filters, fn ($value) => $value !== null);
        $websiteCreateUrl = route('websites.index', [...$websiteIndexQuery, 'dialog' => 'create-website']);
        $websiteStoreUrl = route('websites.store', ['dialog' => 'create-website']);
    @endphp

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        eyebrow="{{ __('Delivery targets') }}"
        icon="globe-alt"
        :title="__('Websites')"
        :description="__('Manage deployment targets and review filtered provisioning and health state.')"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('websites.import.create')" variant="secondary">{{ __('Import existing') }}</x-ui.button>
            <x-ui.button
                :href="$websiteCreateUrl"
                data-modal-trigger="website-create-dialog"
                aria-controls="website-create-dialog"
                aria-expanded="{{ $websiteCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="mr-2 h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Website') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @php($activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null)))

    <x-ui.filter-panel
        id="websites-filters"
        class="mt-8"
        :label="__('Filter websites')"
        :open="$activeFilterCount > 0"
        :summary="$activeFilterCount > 0 ? __(':count active', ['count' => $activeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('websites.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="ui-label">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name, domain, or description') }}"
                    class="ui-input"
                >
            </div>
            <div>
                <label for="status" class="ui-label">{{ __('Status') }}</label>
                <select id="status" name="status" class="ui-input">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="health" class="ui-label">{{ __('Health') }}</label>
                <select id="health" name="health" class="ui-input">
                    <option value="">{{ __('All health states') }}</option>
                    @foreach ($healthStatuses as $health)
                        <option value="{{ $health }}" @selected($filters['health'] === $health)>
                            {{ str($health)->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <label class="ui-choice min-h-11 w-full items-center">
                    <input type="checkbox" name="attention" value="1" @checked($filters['attention']) class="ui-check">
                    {{ __('Needs attention only') }}
                </label>
            </div>
            <div class="flex items-end">
                <label class="ui-choice min-h-11 w-full items-center">
                    <input type="checkbox" name="provisioning" value="1" @checked($filters['provisioning']) class="ui-check">
                    {{ __('Provisioning only') }}
                </label>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            <x-ui.button :href="route('websites.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                {{ __('Export CSV') }}
            </x-ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-ui.button :href="route('websites.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        </form>
    </x-ui.filter-panel>

    <x-ui.insights
        id="websites-insights"
        class="mt-6"
        :summary="trans_choice(':count matching website|:count matching websites', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat :label="__('Matching websites')" :value="$metrics['total']" :description="__('Websites in this filtered view.')" />
            <x-ui.stat :label="__('Active websites')" :value="$metrics['active']" :description="__('Matching provisioned websites.')" />
            <x-ui.stat :label="__('Provisioning')" :value="$metrics['provisioning']" :description="__('Queued or provisioning websites.')" />
            <x-ui.stat :label="__('Failed websites')" :value="$metrics['failed']" :description="__('Matching provisioning failures.')" />
            <x-ui.stat :label="__('Unhealthy websites')" :value="$metrics['unhealthy']" :description="__('Enabled health checks reporting unhealthy.')" />
            <x-ui.stat :label="__('Needs attention')" :value="$metrics['attention']" :description="__('Provisioning failures or enabled unhealthy checks.')" />
        </dl>
    </x-ui.insights>

    <!--
     ! ------------------------------------------------------------
    ! List Websites
     ! ------------------------------------------------------------
     !-->
    @if(!$websites->isEmpty())
        <div class="ui-panel ui-inventory-list mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Website inventory') }}">
            @foreach($websites as $website)
                <article data-website-card class="p-4 sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-avatar :name="$website->name" class="ui-avatar-md shrink-0" />
                            <div class="min-w-0">
                                <a href="{{ route('websites.show', $website) }}" class="ui-link break-words">{{ $website->name }}</a>
                                <p class="truncate text-sm text-muted">{{ $website->url }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($website->provisioning_status === \App\Modules\Deployer\Models\Website::STATUS_ACTIVE)
                                <x-ui.badge tone="success">{{ str($website->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                            @elseif ($website->provisioning_status === \App\Modules\Deployer\Models\Website::STATUS_FAILED)
                                <x-ui.badge tone="danger">{{ str($website->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="accent">{{ str($website->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                            @endif
                            <x-ui.button :href="route('websites.show', $website)" variant="secondary">{{ __('View website') }}</x-ui.button>
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Server') }}</dt>
                            <dd class="mt-1"><a href="{{ route('servers.show', $website->server) }}" class="ui-link">{{ $website->server->label }}</a></dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Health') }}</dt>
                            <dd class="mt-1 text-ink">
                                @if (! $website->health_check_enabled)
                                    <x-ui.badge>{{ __('Disabled') }}</x-ui.badge>
                                @elseif ($website->health_status === \App\Modules\Deployer\Models\Website::HEALTH_HEALTHY)
                                    <x-ui.badge tone="success">{{ $website->health_status }}</x-ui.badge>
                                @elseif ($website->health_status === \App\Modules\Deployer\Models\Website::HEALTH_UNHEALTHY)
                                    <x-ui.badge tone="danger">{{ $website->health_status }}</x-ui.badge>
                                @else
                                    <x-ui.badge>{{ $website->health_status }}</x-ui.badge>
                                @endif
                                @if ($website->health_check_enabled)
                                    @unless ($website->health_monitoring_enabled)
                                        <span class="mt-1 block font-medium text-warning">{{ __('Automatic monitoring paused') }}</span>
                                    @else
                                        <span class="mt-1 block text-muted">{{ trans_choice('Every :count minute|Every :count minutes', $website->health_check_interval_minutes, ['count' => $website->health_check_interval_minutes]) }}</span>
                                    @endunless
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Status') }}</dt>
                            <dd class="mt-1 text-ink">{{ str($website->provisioning_status)->replace('_', ' ')->title() }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Added') }}</dt>
                            <dd class="mt-1 text-ink">{{ $website->created_at->diffForHumans() }}</dd>
                        </div>
                    </dl>
                </article>
            @endforeach
        </div>
        <div class="py-4">
            {{ $websites->links() }}
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No websites match these filters') : __('You have no websites')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('You have no websites. Click the button below to add one.')"
            >
                <x-slot:button>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <x-ui.button :href="route('websites.index')" variant="primary">{{ __('Clear filters') }}</x-ui.button>
                    @else
                        <x-ui.button
                            :href="$websiteCreateUrl"
                            data-modal-trigger="website-create-dialog"
                            aria-controls="website-create-dialog"
                            aria-expanded="{{ $websiteCreateOpen ? 'true' : 'false' }}"
                            variant="secondary"
                        >
                            <svg class="mr-2 h-4 w-4 stroke-2 text-muted">
                                <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                            </svg>
                            {{ __('Add Website') }}
                        </x-ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif

    <x-scenes.websites.create-dialog
        :servers="$servers"
        :plan-usage="$planUsage"
        :website-index-query="$websiteIndexQuery"
        :website-store-url="$websiteStoreUrl"
        :open="$websiteCreateOpen"
    />
</x-layouts.app>
