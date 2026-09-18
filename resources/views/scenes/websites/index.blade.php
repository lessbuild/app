<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        icon="globe-alt"
        :title="__('Websites')"
        :description="__('Manage deployment targets and review filtered provisioning and health state.')"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('websites.import.create')" variant="secondary">{{ __('Import existing') }}</x-ui.button>
            <x-ui.button :href="route('websites.create')" variant="primary">
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
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name, domain, or description') }}"
                    class="input secondary mt-1 w-full rounded-lg"
                >
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="health" class="block text-xs font-semibold uppercase text-secondary">{{ __('Health') }}</label>
                <select id="health" name="health" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All health states') }}</option>
                    @foreach ($healthStatuses as $health)
                        <option value="{{ $health }}" @selected($filters['health'] === $health)>
                            {{ str($health)->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex min-h-[42px] w-full items-center gap-2 rounded-lg border border-primary px-3 text-sm text-primary">
                    <input type="checkbox" name="attention" value="1" @checked($filters['attention'])>
                    {{ __('Needs attention only') }}
                </label>
            </div>
            <div class="flex items-end">
                <label class="flex min-h-[42px] w-full items-center gap-2 rounded-lg border border-primary px-3 text-sm text-primary">
                    <input type="checkbox" name="provisioning" value="1" @checked($filters['provisioning'])>
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

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <x-ui.stat :label="__('Matching websites')" :value="$metrics['total']" :description="__('Websites in this filtered view.')" />
        <x-ui.stat :label="__('Active websites')" :value="$metrics['active']" :description="__('Matching provisioned websites.')" />
        <x-ui.stat :label="__('Provisioning')" :value="$metrics['provisioning']" :description="__('Queued or provisioning websites.')" />
        <x-ui.stat :label="__('Failed websites')" :value="$metrics['failed']" :description="__('Matching provisioning failures.')" />
        <x-ui.stat :label="__('Unhealthy websites')" :value="$metrics['unhealthy']" :description="__('Enabled health checks reporting unhealthy.')" />
        <x-ui.stat :label="__('Needs attention')" :value="$metrics['attention']" :description="__('Provisioning failures or enabled unhealthy checks.')" />
    </dl>

    <!--
     ! ------------------------------------------------------------
     ! List Websites
     ! ------------------------------------------------------------
     !-->
    @if(!$websites->isEmpty())
        <div class="ui-card mt-6 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-primary border-t border-b border-primary">
                <thead class="bg-primary border-l border-r border-primary">
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                            {{ __('Website') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Server') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Status') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Health') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Added') }}
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary bg-primary">
                    @foreach($websites as $website)
                        <tr class="border-l border-r border-primary">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 shrink-0">
                                        <x-avatar :name="$website->name" class="h-10 w-10 rounded-md text-sm" />
                                    </div>
                                    <a href="{{ route('websites.show', $website) }}" class="ml-4">
                                        <div class="font-medium text-ternary">
                                            {{ $website->name }}
                                        </div>
                                        <div class="text-secondary">
                                            {{ $website->url }}
                                        </div>
                                    </a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <a href="{{ route('servers.show', $website->server) }}" class="text-ternary cursor-pointer">
                                    {{ $website->server->label }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                    @if ($website->provisioning_status === \App\Models\Website::STATUS_ACTIVE)
                                        <x-ui.badge tone="success">{{ str($website->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                                    @elseif ($website->provisioning_status === \App\Models\Website::STATUS_FAILED)
                                        <x-ui.badge tone="danger">{{ str($website->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="accent">{{ str($website->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                                    @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                @if (! $website->health_check_enabled)
                                    <x-ui.badge>{{ __('Disabled') }}</x-ui.badge>
                                @else
                                    @if ($website->health_status === \App\Models\Website::HEALTH_HEALTHY)
                                        <x-ui.badge tone="success">{{ $website->health_status }}</x-ui.badge>
                                    @elseif ($website->health_status === \App\Models\Website::HEALTH_UNHEALTHY)
                                        <x-ui.badge tone="danger">{{ $website->health_status }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ $website->health_status }}</x-ui.badge>
                                    @endif
                                    @unless ($website->health_monitoring_enabled)
                                        <div class="text-xs font-medium text-amber-700">{{ __('Automatic monitoring paused') }}</div>
                                    @else
                                        <div class="text-xs text-secondary">
                                            {{ trans_choice('Every :count minute|Every :count minutes', $website->health_check_interval_minutes, ['count' => $website->health_check_interval_minutes]) }}
                                        </div>
                                    @endunless
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary">
                                    {{ $website->created_at->diffForHumans() }}
                                </div>
                            </td>
                            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <a href="{{ route('websites.show', $website) }}">
                                    <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                                        <use xlink:href="/assets/images/icons.svg#chevron-right"></use>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
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
                        <x-ui.button :href="route('websites.create')" variant="secondary">
                            <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                                <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                            </svg>
                            {{ __('Add Website') }}
                        </x-ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
