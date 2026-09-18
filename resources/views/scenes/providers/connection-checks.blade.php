<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to :provider', ['provider' => $provider->name])"
        :route="route('providers.show', $provider)"
    />

    <x-layouts.partials.heading
        :title="__('Connection check history')"
        :description="__('Review the retained credential-check evidence for :provider.', ['provider' => $provider->name])"
    />

    <section class="ui-card mt-8 p-4 sm:p-5" aria-labelledby="connection-check-filters-heading">
        <div class="mb-4">
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Retained evidence') }}</p>
            <h2 id="connection-check-filters-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Filter connection checks') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Narrow the history by result, source and observation date.') }}</p>
        </div>
        <form method="GET" action="{{ route('providers.connection-checks.index', $provider) }}">
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
                <x-ui.button :href="route('providers.connection-checks.export', [$provider, ...array_filter($filters, fn ($value) => $value !== null)])" variant="secondary">
                    {{ __('Export CSV') }}
                </x-ui.button>
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-ui.button :href="route('providers.connection-checks.index', $provider)" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
                @endif
            </div>
        </form>
    </section>

    <x-ui.insights
        id="provider-connection-checks-insights"
        class="mt-6"
        :summary="trans_choice(':count matching check|:count matching checks', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat class="ui-card" :label="__('Matching checks')" :value="$metrics['total']" :description="__('Checks in this filtered retained sample.')" />
            <x-ui.stat class="ui-card" :label="__('Healthy checks')" :value="$metrics['healthy']" :description="__('Matching successful credential checks.')" />
            <x-ui.stat class="ui-card" :label="__('Failed checks')" :value="$metrics['failed']" :description="__('Matching unsuccessful credential checks.')" />
            <x-ui.stat class="ui-card" :label="__('Observed success')" :value="$metrics['success_rate'] !== null ? $metrics['success_rate'].'%' : __('Not available')" :description="__('Recorded rate, not current credential validity.')" />
            <x-ui.stat class="ui-card" :label="__('Median successful response')" :value="$metrics['median_successful_duration_ms'] !== null ? $metrics['median_successful_duration_ms'].' ms' : __('Not recorded')" :description="__('Median of matching successful checks.')" />
            <x-ui.stat class="ui-card" :label="__('Latest matching check')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="__('Newest check in the filtered sample.')" />
        </dl>
    </x-ui.insights>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-secondary">
            {{ trans_choice(':count matching retained check|:count matching retained checks', $connectionChecks->total(), ['count' => $connectionChecks->total()]) }}
        </p>
        <p class="text-xs text-secondary">{{ __('History is limited to the newest :limit retained checks per provider.', ['limit' => \App\Models\ProviderConnectionCheck::MAX_PER_PROVIDER]) }}</p>
    </div>

    @if ($connectionChecks->isEmpty())
        <x-ui.empty-state
            class="mt-4"
            :title="array_filter($filters, fn ($value) => $value !== null) ? __('No connection checks match these filters.') : __('No connection checks have been recorded yet.')"
            :description="__('Run a connection check to create retained evidence for this provider.')"
        />
    @else
        <x-ui.card class="mt-4 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-primary">
                    <caption class="sr-only">{{ __('Provider connection check history') }}</caption>
                    <thead class="bg-secondary">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Result') }}</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Source') }}</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Provider type') }}</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Response') }}</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Endpoint') }}</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Checked') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary">
                        @foreach ($connectionChecks as $check)
                            <tr class="align-top">
                                <td class="px-4 py-4">
                                    <x-ui.badge :tone="$check->successful ? 'success' : 'danger'">{{ $check->successful ? __('Healthy') : __('Failed') }}</x-ui.badge>
                                    @if ($check->error)
                                        <p class="mt-2 max-w-md whitespace-pre-wrap break-words text-xs text-red-700">{{ $check->error }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-primary">{{ str($check->source)->title() }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-primary">{{ str($check->provider_type)->headline() }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-primary">
                                    {{ $check->http_status ? __('HTTP :status', ['status' => $check->http_status]) : __('No status') }}
                                    <span class="mt-1 block text-xs text-secondary">{{ __(':duration ms', ['duration' => $check->duration_ms]) }}</span>
                                </td>
                                <td class="max-w-md break-all px-4 py-4 font-mono text-xs text-primary">{{ $check->endpoint ?? __('Unavailable') }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-right text-sm text-secondary" title="{{ $check->checked_at }}">
                                    {{ $check->checked_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
        <div class="py-4">{{ $connectionChecks->links() }}</div>
    @endif
</x-layouts.app>
