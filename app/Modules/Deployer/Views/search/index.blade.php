<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Search')"
        :description="__('Find infrastructure, source control, recipes, and deployments across your account.')"
    />

    <x-signal.ui.card class="mt-8 p-4 sm:p-5" aria-labelledby="search-form-heading">
        <div class="mb-4">
            <p class="ui-eyebrow">{{ __('Workspace search') }}</p>
            <h2 id="search-form-heading" class="mt-1 text-lg font-bold text-ink">{{ __('Search account') }}</h2>
        </div>
        <form method="GET" action="{{ route('search.index') }}">
            <label for="search-query" class="sr-only">{{ __('Search account') }}</label>
        <div class="mt-2 flex flex-wrap gap-3">
            <x-signal.ui.input
                id="search-query"
                name="q"
                type="search"
                maxlength="100"
                value="{{ $query }}"
                placeholder="{{ __('Name, URL, IP address, revision, or description') }}"
                class="ui-input min-w-0 flex-1"
                autofocus :restore="false" />
            <x-signal.ui.button type="submit" variant="primary">{{ __('Search') }}</x-signal.ui.button>
        </div>
        </form>
    </x-signal.ui.card>

    @if (($unavailable ?? []) !== [])
        <x-signal.ui.alert tone="warning" class="mt-6 text-sm leading-6">
            {{ __('Search could not reach: :apps. Other results are shown.', ['apps' => collect($unavailable)->pluck('label')->implode(', ')]) }}
        </x-signal.ui.alert>
    @endif

    @if ($query === '')
        <div class="mt-8">
            <x-lists.empty
                :title="__('Search your account')"
                :description="__('Enter a resource name, URL, IP address, revision, or description to begin.')"
            />
        </div>
    @else
        @php
            $resultCount = collect($groups)->sum(fn ($group) => $group['results']->count());
            $matchingGroupCount = collect($groups)->filter(fn ($group) => $group['results']->isNotEmpty())->count();
            $moreResultCount = collect($groups)->filter(fn ($group) => $group['has_more'])->count();
        @endphp

        <x-signal.ui.insights
            id="search-insights"
            class="mt-6"
            :summary="trans_choice(':count result shown|:count results shown', $resultCount, ['count' => $resultCount])"
            :mobile-open="true"
        >
            <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-signal.ui.stat
                    :label="__('Results')"
                    :value="$resultCount"
                    :description="__('Matching resources across the account.')"
                />
                <x-signal.ui.stat
                    :label="__('Categories')"
                    :value="$matchingGroupCount"
                    :description="__('Resource groups with a matching result.')"
                />
                <x-signal.ui.stat
                    :label="__('More available')"
                    :value="$moreResultCount"
                    :description="__('Categories with additional matches.')"
                />
                <x-signal.ui.stat
                    :label="__('Search term')"
                    :value="$query"
                    :description="__('Search stays scoped to your account.')"
                />
            </dl>
        </x-signal.ui.insights>

        @if ($resultCount === 0)
            <div class="mt-8">
                <x-lists.empty
                    :title="__('No results for :query', ['query' => $query])"
                    :description="__('Try a broader term or search an individual inventory with its advanced filters.')"
                />
            </div>
        @else
            <p class="mt-6 text-sm text-muted">
                {{ trans_choice(':count result shown|:count results shown', $resultCount, ['count' => $resultCount]) }}
            </p>
            <nav class="mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('Search result groups') }}">
                @foreach ($groups as $key => $group)
                    @if ($group['results']->isNotEmpty())
                        <a href="#search-group-{{ $key }}" class="ui-filter-chip shrink-0">
                            <span>{{ $group['label'] }}</span>
                            <x-signal.ui.badge tone="neutral">{{ $group['results']->count() }}@if ($group['has_more'])+@endif</x-signal.ui.badge>
                        </a>
                    @endif
                @endforeach
            </nav>
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                @foreach ($groups as $key => $group)
                    @if ($group['results']->isNotEmpty())
                        <x-signal.ui.card id="search-group-{{ $key }}" class="scroll-mt-6 p-5" aria-labelledby="search-group-heading-{{ $key }}">
                            <div class="mb-4 flex items-center justify-between gap-3">
                                <h2 id="search-group-heading-{{ $key }}" class="text-lg font-semibold text-ink">{{ $group['label'] }}</h2>
                                <x-signal.ui.badge tone="neutral">{{ $group['results']->count() }}@if ($group['has_more'])+@endif</x-signal.ui.badge>
                                @if ($group['has_more'])
                                    <a href="{{ $group['more_url'] }}" class="ui-link text-sm">
                                        {{ __('View more') }}
                                    </a>
                                @endif
                            </div>
                            <div class="space-y-2">
                                @foreach ($group['results'] as $result)
                                    <a href="{{ $result['url'] }}" class="ui-card ui-card--interactive block bg-surface-muted p-3">
                                        <span class="block font-medium text-ink">{{ $result['title'] }}</span>
                                        @if ($result['subtitle'])
                                            <span class="mt-1 block truncate text-sm text-muted">{{ $result['subtitle'] }}</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </x-signal.ui.card>
                    @endif
                @endforeach
            </div>
        @endif
    @endif
</x-layouts.app>
