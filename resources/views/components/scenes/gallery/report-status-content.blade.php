<div data-report-status-content class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Recipe report') }}</p>
            <h3 class="mt-1 text-xl font-black text-primary">{{ $report->recipe->name }}</h3>
            <p class="mt-1 text-sm text-secondary">{{ str($report->recipe->category)->headline() }}</p>
        </div>
        <x-ui.badge :tone="$report->resolved_at === null ? 'danger' : 'success'">
            {{ $report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor') }}
        </x-ui.badge>
    </div>

    <dl class="grid gap-3 sm:grid-cols-3">
        <x-ui.stat class="ui-card" :label="__('Issue type')" :value="str($report->reason)->headline()" :description="__('The category selected when the report was submitted.')" />
        <x-ui.stat class="ui-card" :label="__('Reported')" :value="$report->created_at->diffForHumans()" :description="$report->created_at->toDayDateTimeString()" />
        <x-ui.stat class="ui-card" :label="__('Last updated')" :value="$report->updated_at->diffForHumans()" :description="$report->updated_at->toDayDateTimeString()" />
    </dl>

    <div>
        <h4 class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Your report details') }}</h4>
        <p class="mt-2 whitespace-pre-line text-sm text-primary">{{ $report->details ?: __('No additional details were provided.') }}</p>
    </div>

    @if ($report->resolved_at && $report->resolution_note)
        <div class="rounded-xl border border-green-300 bg-green-50 p-4 text-green-900">
            <h4 class="text-sm font-bold">{{ __('Contributor resolution note') }}</h4>
            <p class="mt-2 whitespace-pre-line text-sm">{{ $report->resolution_note }}</p>
        </div>
    @endif

    <div class="flex flex-wrap gap-2">
        <x-ui.button :href="route('gallery.report.status', $report)" variant="secondary">{{ __('Open full report status') }}</x-ui.button>
        @if ($report->recipe->is_published && $report->recipe->published_at)
            <x-ui.button :href="route('gallery.show', $report->recipe).'#gallery-report-heading'" variant="ghost">{{ __('View recipe') }}</x-ui.button>
        @endif
    </div>
</div>
