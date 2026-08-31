<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Activity')"
        :description="__('A chronological history of account security, infrastructure, deployments, and server commands.')"
    />

    <form method="GET" action="{{ route('activity.index') }}" class="mb-6 mt-8 rounded-lg border border-primary bg-primary p-4">
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
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
            <div>
                <label for="category" class="block text-xs font-semibold uppercase text-secondary">{{ __('Category') }}</label>
                <select id="category" name="category" class="input secondary mt-1 w-full rounded">
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
                <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-1 w-full rounded">
            </div>
            <div>
                <label for="date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('To') }}</label>
                <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-1 w-full rounded">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('activity.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button primary">
                {{ __('Export CSV') }}
            </a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('activity.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <x-activity-feed
        :events="$events"
        :empty-title="array_filter($filters, fn ($value) => $value !== null) ? __('No activity matches these filters') : __('No activity yet')"
        :empty-description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('Infrastructure and deployment updates will appear here.')"
    />

    <div class="mt-6">
        {{ $events->links() }}
    </div>
</x-layouts.app>
