<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :website', ['website' => $website->name])"
        :route="route('websites.show', $website)"
    />

    <x-layouts.partials.heading
        :title="__('Health check history')"
        :description="__('Review the retained health evidence for :website.', ['website' => $website->name])"
    />

    <section class="ui-card mt-8 p-4 sm:p-5" aria-labelledby="health-check-filters-heading">
        <div class="mb-4">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Retained evidence') }}</p>
            <h2 id="health-check-filters-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Filter health checks') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Narrow the history by result, source and observation date.') }}</p>
        </div>
        <form method="GET" action="{{ route('websites.health-checks.index', $website) }}">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label for="result" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Result') }}</label>
                    <select id="result" name="result" class="input secondary mt-2 w-full rounded-lg">
                        <option value="">{{ __('All results') }}</option>
                        <option value="healthy" @selected($filters['result'] === 'healthy')>{{ __('Healthy') }}</option>
                        <option value="failed" @selected($filters['result'] === 'failed')>{{ __('Failed') }}</option>
                    </select>
                </div>
                <div>
                    <label for="source" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Source') }}</label>
                    <select id="source" name="source" class="input secondary mt-2 w-full rounded-lg">
                        <option value="">{{ __('All sources') }}</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source }}" @selected($filters['source'] === $source)>{{ str($source)->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_from" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Checked from') }}</label>
                    <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input secondary mt-2 w-full rounded-lg">
                </div>
                <div>
                    <label for="date_to" class="block text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Checked through') }}</label>
                    <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input secondary mt-2 w-full rounded-lg">
                </div>
            </div>
            <div class="mt-5 flex flex-wrap gap-2">
                <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('websites.health-checks.export', [$website, ...array_filter($filters, fn ($value) => $value !== null)])" variant="secondary">
                    {{ __('Export CSV') }}
                </x-ui.button>
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-ui.button :href="route('websites.health-checks.index', $website)" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
                @endif
            </div>
        </form>
    </section>

    <x-ui.insights
        id="health-checks-insights"
        class="mt-6"
        :summary="trans_choice(':count matching check|:count matching checks', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat class="ui-card" :label="__('Matching checks')" :value="$metrics['total']" :description="__('Checks in this filtered retained sample.')" />
            <x-ui.stat class="ui-card" :label="__('Healthy checks')" :value="$metrics['healthy']" :description="__('Matching successful responses.')" />
            <x-ui.stat class="ui-card" :label="__('Failed checks')" :value="$metrics['failed']" :description="__('Matching unsuccessful responses.')" />
            <x-ui.stat class="ui-card" :label="__('Observed success')" :value="$metrics['success_rate'] !== null ? $metrics['success_rate'].'%' : __('Not available')" :description="__('Recorded sample rate, not SLA uptime.')" />
            <x-ui.stat class="ui-card" :label="__('Median healthy response')" :value="$metrics['median_healthy_duration_ms'] !== null ? $metrics['median_healthy_duration_ms'].' ms' : __('Not recorded')" :description="__('Median of matching successful checks.')" />
            <x-ui.stat class="ui-card" :label="__('Latest matching check')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="__('Newest check in the filtered sample.')" />
        </dl>
    </x-ui.insights>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-secondary">
            {{ trans_choice(':count matching retained check|:count matching retained checks', $healthChecks->total(), ['count' => $healthChecks->total()]) }}
        </p>
        <p class="text-xs text-secondary">{{ __('History is limited to the newest :limit retained checks per website.', ['limit' => \App\Models\WebsiteHealthCheck::MAX_PER_WEBSITE]) }}</p>
    </div>

    @if ($healthChecks->isEmpty())
        <x-ui.empty-state
            class="mt-4"
            :title="array_filter($filters, fn ($value) => $value !== null) ? __('No health checks match these filters.') : __('No health checks have been recorded yet.')"
            :description="__('Run or wait for a health check to create retained evidence for this website.')"
        />
    @else
        <x-ui.card class="mt-4 overflow-hidden">
            <div class="divide-y divide-primary" aria-label="{{ __('Website health check history') }}">
                @foreach ($healthChecks as $check)
                    @include('scenes.websites._health-check-card', ['check' => $check])
                @endforeach
            </div>
        </x-ui.card>
        <div class="py-4">{{ $healthChecks->links() }}</div>
    @endif
</x-layouts.app>
