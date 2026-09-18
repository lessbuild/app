<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Search')"
        :description="__('Find infrastructure, source control, recipes, and deployments across your account.')"
    />

    <x-ui.card class="mt-8 p-4 sm:p-5" aria-labelledby="search-form-heading">
        <div class="mb-4">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Workspace search') }}</p>
            <h2 id="search-form-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Search account') }}</h2>
        </div>
        <form method="GET" action="{{ route('search.index') }}">
            <label for="search-query" class="sr-only">{{ __('Search account') }}</label>
        <div class="mt-2 flex flex-wrap gap-3">
            <input
                id="search-query"
                name="q"
                type="search"
                maxlength="100"
                value="{{ $query }}"
                placeholder="{{ __('Name, URL, IP address, revision, or description') }}"
                class="input secondary min-w-0 flex-1 rounded-lg"
                autofocus
            >
            <x-ui.button type="submit" variant="primary">{{ __('Search') }}</x-ui.button>
        </div>
        </form>
    </x-ui.card>

    @if ($query === '')
        <div class="mt-8">
            <x-lists.empty
                :title="__('Search your account')"
                :description="__('Enter a resource name, URL, IP address, revision, or description to begin.')"
            />
        </div>
    @else
        @php($resultCount = collect($groups)->sum(fn ($group) => $group['results']->count()))
        @if ($resultCount === 0)
            <div class="mt-8">
                <x-lists.empty
                    :title="__('No results for :query', ['query' => $query])"
                    :description="__('Try a broader term or search an individual inventory with its advanced filters.')"
                />
            </div>
        @else
            <p class="mt-6 text-sm text-secondary">
                {{ trans_choice(':count result shown|:count results shown', $resultCount, ['count' => $resultCount]) }}
            </p>
            <nav class="mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('Search result groups') }}">
                @foreach ($groups as $key => $group)
                    @if ($group['results']->isNotEmpty())
                        <a href="#search-group-{{ $key }}" class="flex shrink-0 items-center gap-2 rounded-lg border border-primary bg-primary px-3 py-2 text-sm font-semibold text-primary hover:bg-secondary">
                            <span>{{ $group['label'] }}</span>
                            <x-ui.badge tone="neutral">{{ $group['results']->count() }}@if ($group['has_more'])+@endif</x-ui.badge>
                        </a>
                    @endif
                @endforeach
            </nav>
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                @foreach ($groups as $key => $group)
                    @if ($group['results']->isNotEmpty())
                        <x-ui.card id="search-group-{{ $key }}" class="scroll-mt-6 p-5" aria-labelledby="search-group-heading-{{ $key }}">
                            <div class="mb-4 flex items-center justify-between gap-3">
                                <h2 id="search-group-heading-{{ $key }}" class="text-lg font-semibold text-primary">{{ $group['label'] }}</h2>
                                <x-ui.badge tone="neutral">{{ $group['results']->count() }}@if ($group['has_more'])+@endif</x-ui.badge>
                                @if ($group['has_more'])
                                    <a href="{{ $group['more_url'] }}" class="text-sm font-medium text-ternary underline">
                                        {{ __('View more') }}
                                    </a>
                                @endif
                            </div>
                            <div class="space-y-2">
                                @foreach ($group['results'] as $result)
                                    <a href="{{ $result['url'] }}" class="ui-card ui-card--interactive block bg-secondary p-3">
                                        <span class="block font-medium text-primary">{{ $result['title'] }}</span>
                                        @if ($result['subtitle'])
                                            <span class="mt-1 block truncate text-sm text-secondary">{{ $result['subtitle'] }}</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </x-ui.card>
                    @endif
                @endforeach
            </div>
        @endif
    @endif
</x-layouts.app>
