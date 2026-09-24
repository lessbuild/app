@php
    $filterIdPrefix ??= '';
    $filterAction ??= route('account.sign-ins.index');
    $filterFragmentAction ??= null;
    $clearFiltersUrl ??= route('account.sign-ins.index');
    $exportUrl = route('account.sign-ins.export', array_filter($filters, fn ($value) => $value !== null));
@endphp

<x-signal.ui.panel as="section" id="{{ $filterIdPrefix }}sign-in-filters" class="ui-panel scroll-mt-24 p-4 sm:p-5" aria-labelledby="{{ $filterIdPrefix }}sign-in-filters-heading">
    <div class="mb-4">
        <p class="ui-eyebrow text-[0.65rem]">{{ __('Security history') }}</p>
        <h2 id="{{ $filterIdPrefix }}sign-in-filters-heading" class="mt-1 text-lg font-bold text-ink">{{ __('Filter sign-ins') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('Narrow successful sign-ins by method and date.') }}</p>
    </div>
    <form
        method="GET"
        action="{{ $filterAction }}"
        @if ($filterFragmentAction)
            data-modal-fragment-form
            data-modal-fragment-url="{{ $filterFragmentAction }}"
        @endif
    >
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="{{ $filterIdPrefix }}method" class="ui-label">{{ __('Method') }}</label>
                <x-signal.ui.select id="{{ $filterIdPrefix }}method" name="method" class="ui-input">
                    <option value="">{{ __('All methods') }}</option>
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected($filters['method'] === $value)>{{ $label }}</option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="{{ $filterIdPrefix }}date-from" class="ui-label">{{ __('Signed in from') }}</label>
                <x-signal.ui.input id="{{ $filterIdPrefix }}date-from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="ui-input" :restore="false" />
            </div>
            <div>
                <label for="{{ $filterIdPrefix }}date-to" class="ui-label">{{ __('Signed in through') }}</label>
                <x-signal.ui.input id="{{ $filterIdPrefix }}date-to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="ui-input" :restore="false" />
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            <x-signal.ui.button href="{{ $exportUrl }}" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                @if ($filterFragmentAction)
                    <x-signal.ui.button
                        href="{{ $clearFiltersUrl }}"
                        data-modal-fragment-link
                        data-modal-fragment-url="{{ $filterFragmentAction }}"
                        variant="ghost"
                    >{{ __('Clear filters') }}</x-signal.ui.button>
                @else
                    <x-signal.ui.button href="{{ $clearFiltersUrl }}" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
                @endif
            @endif
        </div>
    </form>
</x-signal.ui.panel>

<div class="mt-6 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-muted">
        {{ trans_choice(':count matching sign-in|:count matching sign-ins', $signIns->total(), ['count' => $signIns->total()]) }}
    </p>
    <p class="text-xs text-muted">{{ __('Only successful sign-ins are recorded. Raw browser user agents are never displayed or exported.') }}</p>
</div>

<x-signal.ui.insights
    id="{{ $filterIdPrefix }}sign-in-insights"
    class="mt-4 scroll-mt-24"
    :summary="trans_choice(':count matching sign-in|:count matching sign-ins', $metrics['total'], ['count' => $metrics['total']])"
>
    <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-signal.ui.stat class="ui-card" :label="__('Matching sign-ins')" :value="$metrics['total']" :description="__('Successful events in this filtered view.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Password sign-ins')" :value="$metrics['password']" :description="__('Authenticated with the local password.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Social sign-ins')" :value="$metrics['social']" :description="__('Recognized GitHub, GitLab, or Bitbucket events.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Known IP addresses')" :value="$metrics['known_ips']" :description="__('Distinct validated addresses in this view.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Latest matching sign-in')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching event recorded.')" />
    </dl>
</x-signal.ui.insights>

<div id="{{ $filterIdPrefix }}sign-in-history" class="mt-4 scroll-mt-24">
@if ($signIns->isEmpty())
    <x-signal.ui.empty-state :title="array_filter($filters, fn ($value) => $value !== null) ? __('No sign-ins match these filters.') : __('No sign-in history yet.')" />
@else
    <x-signal.ui.panel data-sign-in-cards class="ui-panel divide-y divide-line">
        @foreach ($signIns as $signIn)
            <article data-sign-in-card class="p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-ink">{{ $signIn['device'] }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $signIn['method'] }}</p>
                    </div>
                    <time class="text-right text-sm text-muted" datetime="{{ $signIn['signed_in_at']->toIso8601String() }}" title="{{ $signIn['signed_in_at']->toDayDateTimeString() }}">
                        {{ $signIn['signed_in_at']->diffForHumans() }}
                    </time>
                </div>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Method') }}</dt>
                        <dd class="mt-1 text-ink">{{ $signIn['method'] }}</dd>
                    </div>
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('IP address') }}</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-ink">{{ $signIn['ip_address'] }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Signed in') }}</dt>
                        <dd class="mt-1 text-ink">{{ $signIn['signed_in_at']->toDayDateTimeString() }}</dd>
                    </div>
                </dl>
            </article>
        @endforeach
    </x-signal.ui.panel>
    <div class="py-4">{{ $signIns->links() }}</div>
@endif
</div>
