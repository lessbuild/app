<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Search')"
        :description="__('Find infrastructure, source control, recipes, and deployments across your account.')"
    />

    <form method="GET" action="{{ route('search.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <label for="search-query" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search account') }}</label>
        <div class="mt-2 flex flex-wrap gap-3">
            <input
                id="search-query"
                name="q"
                type="search"
                maxlength="100"
                value="{{ $query }}"
                placeholder="{{ __('Name, URL, IP address, revision, or description') }}"
                class="input secondary min-w-0 flex-1 rounded-sm"
                autofocus
            >
            <button type="submit" class="button primary">{{ __('Search') }}</button>
        </div>
    </form>

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
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                @foreach ($groups as $group)
                    @if ($group['results']->isNotEmpty())
                        <section class="rounded-lg border border-primary bg-primary p-5">
                            <div class="mb-4 flex items-center justify-between gap-3">
                                <h2 class="text-lg font-semibold text-primary">{{ $group['label'] }}</h2>
                                @if ($group['has_more'])
                                    <a href="{{ $group['more_url'] }}" class="text-sm font-medium text-ternary underline">
                                        {{ __('View more') }}
                                    </a>
                                @endif
                            </div>
                            <div class="space-y-2">
                                @foreach ($group['results'] as $result)
                                    <a href="{{ $result['url'] }}" class="block rounded-sm border border-primary bg-secondary p-3 hover:border-ternary">
                                        <span class="block font-medium text-primary">{{ $result['title'] }}</span>
                                        @if ($result['subtitle'])
                                            <span class="mt-1 block truncate text-sm text-secondary">{{ $result['subtitle'] }}</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            </div>
        @endif
    @endif
</x-layouts.app>
