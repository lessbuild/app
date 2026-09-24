<div data-report-status-content class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Recipe report') }}</p>
            <h3 class="mt-1 text-xl font-extrabold text-ink">{{ $report->recipe->name }}</h3>
            <p class="mt-1 text-sm text-muted">{{ str($report->recipe->category)->headline() }}</p>
        </div>
        <x-signal.ui.badge :tone="$report->resolved_at === null ? 'danger' : 'success'">
            {{ $report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor') }}
        </x-signal.ui.badge>
    </div>

    <dl class="grid gap-3 sm:grid-cols-3">
        <x-signal.ui.stat class="ui-card" :label="__('Issue type')" :value="str($report->reason)->headline()" :description="__('The category selected when the report was submitted.')" />
        <x-signal.ui.stat class="ui-card" :label="__('Reported')" :value="$report->created_at->diffForHumans()" :description="$report->created_at->toDayDateTimeString()" />
        <x-signal.ui.stat class="ui-card" :label="__('Last updated')" :value="$report->updated_at->diffForHumans()" :description="$report->updated_at->toDayDateTimeString()" />
    </dl>

    <div>
        <h4 class="ui-eyebrow">{{ __('Your report details') }}</h4>
        <p class="mt-2 whitespace-pre-line text-sm text-ink">{{ $report->details ?: __('No additional details were provided.') }}</p>
    </div>

    @if ($report->resolved_at && $report->resolution_note)
        <div class="ui-alert ui-alert--success p-4">
            <h4 class="text-sm font-bold">{{ __('Contributor resolution note') }}</h4>
            <p class="mt-2 whitespace-pre-line text-sm">{{ $report->resolution_note }}</p>
        </div>
    @endif

    <div class="flex flex-wrap gap-2">
        <x-signal.ui.button :href="route('gallery.report.status', $report)" variant="secondary">{{ __('Open full report status') }}</x-signal.ui.button>
        @if ($report->recipe->is_published && $report->recipe->published_at)
            <x-signal.ui.button :href="route('gallery.show', $report->recipe).'#gallery-report-heading'" variant="ghost">{{ __('View recipe') }}</x-signal.ui.button>
        @endif
    </div>
</div>
