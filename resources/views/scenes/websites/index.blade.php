<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="__('Manage Websites')"
        :description="__('Easily manage your websites')"
    >
        <x-slot:buttons>
            <a
                href="{{ route('websites.create') }}"
                class="flex items-center bg-primary px-3 py-2 text-primary text-xs rounded border border-primary"
            >
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Website') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <form method="GET" action="{{ route('websites.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
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
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded">
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
                <select id="health" name="health" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All health states') }}</option>
                    @foreach ($healthStatuses as $health)
                        <option value="{{ $health }}" @selected($filters['health'] === $health)>
                            {{ str($health)->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex min-h-[42px] w-full items-center gap-2 rounded border border-primary px-3 text-sm text-primary">
                    <input type="checkbox" name="attention" value="1" @checked($filters['attention'])>
                    {{ __('Needs attention only') }}
                </label>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('websites.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button primary">
                {{ __('Export CSV') }}
            </a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('websites.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <!--
     ! ------------------------------------------------------------
     ! List Websites
     ! ------------------------------------------------------------
     !-->
    @if(!$websites->isEmpty())
        <div class="mt-6 overflow-x-auto">
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
                                    <div class="h-10 w-10 flex-shrink-0">
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
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                                    'bg-green-100 text-green-700' => $website->provisioning_status === \App\Models\Website::STATUS_ACTIVE,
                                    'bg-red-100 text-red-700' => $website->provisioning_status === \App\Models\Website::STATUS_FAILED,
                                    'bg-blue-100 text-blue-700' => ! in_array($website->provisioning_status, [\App\Models\Website::STATUS_ACTIVE, \App\Models\Website::STATUS_FAILED], true),
                                ])>{{ str($website->provisioning_status)->replace('_', ' ') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                @if (! $website->health_check_enabled)
                                    {{ __('Disabled') }}
                                @else
                                    <span @class([
                                        'font-medium uppercase',
                                        'text-green-600' => $website->health_status === \App\Models\Website::HEALTH_HEALTHY,
                                        'text-red-600' => $website->health_status === \App\Models\Website::HEALTH_UNHEALTHY,
                                        'text-secondary' => $website->health_status === \App\Models\Website::HEALTH_UNKNOWN,
                                    ])>{{ $website->health_status }}</span>
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
                        <a href="{{ route('websites.index') }}" class="button primary">{{ __('Clear filters') }}</a>
                    @else
                        <a href="{{ route('websites.create') }}" class="button secondary">
                            <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                                <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                            </svg>
                            {{ __('Add Website') }}
                        </a>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
