@php
    $filterIdPrefix ??= '';
    $filterAction ??= route('websites.health-checks.index', $website);
    $filterFragmentAction ??= null;
    $exportUrl = route('websites.health-checks.export', [$website, ...array_filter($filters, fn ($value) => $value !== null)]);
@endphp

<x-signal.ui.panel as="section" class="ui-panel p-4 sm:p-5" aria-labelledby="{{ $filterIdPrefix }}health-check-filters-heading">
    <div class="mb-4">
        <p class="ui-eyebrow">{{ __('Retained evidence') }}</p>
        <h2 id="{{ $filterIdPrefix }}health-check-filters-heading" class="mt-2 text-lg font-extrabold text-ink">{{ __('Filter health checks') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('Narrow the history by result, source and observation date.') }}</p>
    </div>
    <form
        method="GET"
        action="{{ $filterAction }}"
        @if ($filterFragmentAction)
            data-modal-fragment-form
            data-modal-fragment-url="{{ $filterFragmentAction }}"
        @endif
    >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="{{ $filterIdPrefix }}result" class="ui-label">{{ __('Result') }}</label>
                <x-signal.ui.select id="{{ $filterIdPrefix }}result" name="result" class="ui-input">
                    <option value="">{{ __('All results') }}</option>
                    <option value="healthy" @selected($filters['result'] === 'healthy')>{{ __('Healthy') }}</option>
                    <option value="failed" @selected($filters['result'] === 'failed')>{{ __('Failed') }}</option>
                </x-signal.ui.select>
            </div>
            <div>
                <label for="{{ $filterIdPrefix }}source" class="ui-label">{{ __('Source') }}</label>
                <x-signal.ui.select id="{{ $filterIdPrefix }}source" name="source" class="ui-input">
                    <option value="">{{ __('All sources') }}</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" @selected($filters['source'] === $source)>{{ str($source)->title() }}</option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="{{ $filterIdPrefix }}date-from" class="ui-label">{{ __('Checked from') }}</label>
                <x-signal.ui.input id="{{ $filterIdPrefix }}date-from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="ui-input" :restore="false" />
            </div>
            <div>
                <label for="{{ $filterIdPrefix }}date-to" class="ui-label">{{ __('Checked through') }}</label>
                <x-signal.ui.input id="{{ $filterIdPrefix }}date-to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="ui-input" :restore="false" />
            </div>
        </div>
        <div class="mt-5 flex flex-wrap gap-2">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            <x-signal.ui.button :href="$exportUrl" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                @if ($filterFragmentAction)
                    <x-signal.ui.button
                        :href="route('websites.health-checks.index', $website)"
                        data-modal-fragment-link
                        data-modal-fragment-url="{{ $filterFragmentAction }}"
                        variant="ghost"
                    >{{ __('Clear filters') }}</x-signal.ui.button>
                @else
                    <x-signal.ui.button :href="route('websites.health-checks.index', $website)" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
                @endif
            @endif
        </div>
    </form>
</x-signal.ui.panel>

<x-signal.ui.insights
    id="{{ $filterIdPrefix }}health-checks-insights"
    class="mt-6"
    :summary="trans_choice(':count matching check|:count matching checks', $metrics['total'], ['count' => $metrics['total']])"
>
    <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <x-signal.ui.stat class="ui-card" :label="__('Matching checks')" :value="$metrics['total']" :description="__('Checks in this filtered retained sample.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Healthy checks')" :value="$metrics['healthy']" :description="__('Matching successful responses.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Failed checks')" :value="$metrics['failed']" :description="__('Matching unsuccessful responses.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Observed success')" :value="$metrics['success_rate'] !== null ? $metrics['success_rate'].'%' : __('Not available')" :description="__('Recorded sample rate, not SLA uptime.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Median healthy response')" :value="$metrics['median_healthy_duration_ms'] !== null ? $metrics['median_healthy_duration_ms'].' ms' : __('Not recorded')" :description="__('Median of matching successful checks.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Latest matching check')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="__('Newest check in the filtered sample.')" />
    </dl>
</x-signal.ui.insights>

<div class="mt-6 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-muted">
        {{ trans_choice(':count matching retained check|:count matching retained checks', $healthChecks->total(), ['count' => $healthChecks->total()]) }}
    </p>
    <p class="text-xs text-muted">{{ __('History is limited to the newest :limit retained checks per website.', ['limit' => \App\Modules\Deployer\Models\WebsiteHealthCheck::MAX_PER_WEBSITE]) }}</p>
</div>

@if ($healthChecks->isEmpty())
    <x-signal.ui.empty-state
        class="mt-4"
        :title="array_filter($filters, fn ($value) => $value !== null) ? __('No health checks match these filters.') : __('No health checks have been recorded yet.')"
        :description="__('Run or wait for a health check to create retained evidence for this website.')"
    />
@else
    <x-signal.ui.card class="mt-4 overflow-hidden">
        <div class="divide-y divide-line" aria-label="{{ __('Website health check history') }}">
            @foreach ($healthChecks as $check)
                @include('scenes.websites._health-check-card', ['check' => $check])
            @endforeach
        </div>
    </x-signal.ui.card>
    <div class="py-4">{{ $healthChecks->links() }}</div>
@endif
