@php
    $fullPageUrl ??= route('activity.index', array_filter($filters, fn ($value) => $value !== null));
    $exportUrl = route('activity.export', array_filter($filters, fn ($value) => $value !== null));
@endphp

<section data-activity-history-content aria-labelledby="workspace-activity-content-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Workspace activity') }}</p>
            <h2 id="workspace-activity-content-heading" class="mt-1 text-lg font-bold text-ink">{{ __('Recent activity') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Review deployment, infrastructure, command, recipe and account events without leaving the dashboard.') }}</p>
        </div>
        <span class="text-xs text-muted">{{ trans_choice(':count matching event|:count matching events', $metrics['total'], ['count' => $metrics['total']]) }}</span>
    </div>

    <x-signal.ui.insights
        id="workspace-activity-insights"
        class="mt-5"
        :summary="trans_choice(':count matching event|:count matching events', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-signal.ui.stat class="ui-card" :label="__('Events')" :value="$metrics['total']" :description="__('Owner-scoped events in this workspace.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Deployments')" :value="$metrics['deployments']" :description="__('Matching deployment events.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Latest event')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching event recorded.')" />
        </dl>
    </x-signal.ui.insights>

    <div class="mt-5">
        <x-activity-feed
            :events="$events"
            :empty-title="__('No activity yet')"
            :empty-description="__('Deployment and infrastructure updates will appear here.')"
        />
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm">{{ $events->links() }}</div>
        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button :href="$fullPageUrl" variant="secondary">{{ __('Open full activity') }}</x-signal.ui.button>
            @if ($auditAvailable)
                <x-signal.ui.button :href="$exportUrl" variant="ghost">{{ __('Export CSV') }}</x-signal.ui.button>
            @endif
        </div>
    </div>
</section>
