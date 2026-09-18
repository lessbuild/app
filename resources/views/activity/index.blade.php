<x-layouts.app>
    <x-layouts.partials.heading
        icon="activity"
        :title="__('Activity')"
        :description="__('A chronological history of account security, infrastructure, deployments, recipes, and server commands.')"
    />

    @php
        $activityFilterCount = collect($filters)->filter(fn ($value) => filled($value))->count();
    @endphp

    <x-ui.filter-panel
        id="activity-filters"
        class="mb-6 mt-8"
        :open="$activityFilterCount > 0"
        :summary="$activityFilterCount > 0 ? trans_choice(':count active filter|:count active filters', $activityFilterCount, ['count' => $activityFilterCount]) : null"
    >
        <form method="GET" action="{{ route('activity.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Activity message') }}"
                    class="input secondary mt-1 w-full rounded-lg"
                >
            </div>
            <div>
                <label for="category" class="block text-xs font-semibold uppercase text-secondary">{{ __('Category') }}</label>
                <select id="category" name="category" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>
                            {{ str($category)->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('From') }}</label>
                <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded-lg">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('To') }}</label>
                <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded-lg">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            @if ($auditAvailable)
                <x-ui.button :href="route('activity.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                    {{ __('Export CSV') }}
                </x-ui.button>
            @else
                <x-ui.button :href="route('billing.index')" variant="secondary">{{ __('Unlock CSV export') }}</x-ui.button>
            @endif
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-ui.button :href="route('activity.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        </form>
    </x-ui.filter-panel>

    <x-ui.insights
        id="activity-insights"
        class="mb-6"
        :open="$metrics['total'] === 0 && $activityFilterCount > 0"
        :mobile-open="$metrics['total'] === 0 && $activityFilterCount > 0"
        :summary="trans_choice(':count matching event|:count matching events', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
            <x-ui.stat :label="__('Matching events')" :value="$metrics['total']" :description="__('Audit events in this filtered view.')" />
            <x-ui.stat :label="__('Deployments')" :value="$metrics['deployments']" :description="__('Matching deployment events.')" />
            <x-ui.stat :label="__('Infrastructure')" :value="$metrics['infrastructure']" :description="__('Website, server, and provider events.')" />
            <x-ui.stat :label="__('Server commands')" :value="$metrics['commands']" :description="__('Matching command lifecycle events.')" />
            <x-ui.stat :label="__('Recipes')" :value="$metrics['recipes']" :description="__('Matching recipe and gallery events.')" />
            <x-ui.stat :label="__('Account security')" :value="$metrics['account']" :description="__('Matching account security events.')" />
            <x-ui.stat :label="__('Latest matching event')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching event recorded.')" />
        </dl>
    </x-ui.insights>

    <x-activity-feed
        :events="$events"
        :empty-title="array_filter($filters, fn ($value) => $value !== null) ? __('No activity matches these filters') : __('No activity yet')"
        :empty-description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('Infrastructure and deployment updates will appear here.')"
    />

    <div class="mt-6">
        {{ $events->links() }}
    </div>
</x-layouts.app>
