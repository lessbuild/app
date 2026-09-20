@php
    $fullPageUrl ??= route('activity.index', array_filter($filters, fn ($value) => $value !== null));
    $exportUrl = route('activity.export', array_filter($filters, fn ($value) => $value !== null));
@endphp

<section data-activity-audit-content aria-labelledby="account-audit-content-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Account security') }}</p>
            <h2 id="account-audit-content-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Recent account audit') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Owner-scoped security changes without credentials, session details, or provider identities.') }}</p>
        </div>
        <span class="text-xs text-secondary">{{ trans_choice(':count matching event|:count matching events', $metrics['total'], ['count' => $metrics['total']]) }}</span>
    </div>

    <x-ui.insights
        id="account-audit-insights"
        class="mt-5"
        :summary="trans_choice(':count matching account event|:count matching account events', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.stat class="ui-card" :label="__('Account events')" :value="$metrics['account']" :description="__('Matching account-security changes.')" />
            <x-ui.stat class="ui-card" :label="__('Matching events')" :value="$metrics['total']" :description="__('Owner-scoped events in this view.')" />
            <x-ui.stat class="ui-card" :label="__('Latest event')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching event recorded.')" />
        </dl>
    </x-ui.insights>

    <div class="mt-5">
        <x-activity-feed
            :events="$events"
            :empty-title="__('No account activity yet')"
            :empty-description="__('Security changes will appear here without credential, provider identity, session, or network details.')"
        />
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm">{{ $events->links() }}</div>
        <div class="flex flex-wrap gap-3">
            <x-ui.button :href="$fullPageUrl" variant="secondary">{{ __('Open full audit') }}</x-ui.button>
            @if ($auditAvailable)
                <x-ui.button :href="$exportUrl" variant="ghost">{{ __('Export CSV') }}</x-ui.button>
            @endif
        </div>
    </div>
</section>
