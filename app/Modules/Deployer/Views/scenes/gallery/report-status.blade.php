<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('gallery.reports.mine')" :title="__('Back to my reports')" />

    <x-layouts.partials.heading
        :title="__('My Report Status')"
        :description="__('Review the current state of your private community report and any response from the contributor.')"
    />

    <x-ui.local-nav class="mt-6" :label="__('Report status sections')">
        <a href="#report-status-overview" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#gallery-report-status-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#report-status-details" class="ui-local-nav__link">{{ __('Details') }}</a>
    </x-ui.local-nav>

    @if ($unreadUpdate)
        <x-ui.alert class="mt-6 flex flex-wrap items-center justify-between gap-3 p-4" tone="info" role="status">
            <div>
                <p class="font-semibold">{{ __('New contributor update') }}</p>
                <p class="mt-1 text-sm">{{ __('Review the current state below, then mark this update as reviewed.') }}</p>
            </div>
            <form method="POST" action="{{ route('notifications.read', $unreadUpdate) }}">
                @csrf
                <x-ui.button type="submit" variant="primary">{{ __('Mark update reviewed') }}</x-ui.button>
            </form>
        </x-ui.alert>
    @endif

    <section id="report-status-overview" class="ui-panel mt-6 scroll-mt-24 p-5 sm:p-6" aria-labelledby="report-status-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow text-[0.65rem]">{{ __('Recipe') }}</p>
                <h2 id="report-status-heading" class="mt-1 text-xl font-bold text-ink">{{ $report->recipe->name }}</h2>
                <p class="mt-1 text-sm text-muted">{{ str($report->recipe->category)->headline() }}</p>
            </div>
            <x-ui.badge :tone="$report->resolved_at === null ? 'danger' : 'success'">{{ $report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor') }}</x-ui.badge>
        </div>

        @if ($report->recipe->is_published && $report->recipe->published_at)
            <a href="{{ route('gallery.show', $report->recipe) }}#gallery-report-heading" class="ui-link mt-4 inline-block">
                {{ __('View or update this report in the gallery') }}
            </a>
        @else
            <x-ui.alert class="mt-4 p-3" tone="warning">
                {{ __('This recipe is no longer published. Your report status remains available, but the gallery recipe cannot be opened or updated.') }}
            </x-ui.alert>
        @endif

        <x-ui.insights
            id="gallery-report-status-insights"
            class="mt-5 scroll-mt-24"
            :summary="$report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor')"
            :mobile-open="true"
        >
            <dl class="ui-insight-grid grid gap-4 sm:grid-cols-3">
                <x-ui.stat
                    :label="__('Issue type')"
                    :value="str($report->reason)->headline()"
                    :description="__('The category selected when the report was submitted.')"
                />
                <x-ui.stat
                    :label="__('Reported')"
                    :value="$report->created_at->diffForHumans()"
                    :description="$report->created_at->toDayDateTimeString()"
                />
                <x-ui.stat
                    :label="__('Last updated')"
                    :value="$report->updated_at->diffForHumans()"
                    :description="$report->updated_at->toDayDateTimeString()"
                />
            </dl>
        </x-ui.insights>

        <div id="report-status-details" class="mt-5 scroll-mt-24">
            <h3 class="ui-eyebrow text-[0.65rem]">{{ __('Your report details') }}</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-ink">{{ $report->details ?: __('No additional details were provided.') }}</p>
        </div>

        @if ($report->resolved_at && $report->resolution_note)
            <x-ui.alert class="mt-5 p-4" tone="success">
                <h3 class="text-sm font-semibold">{{ __('Contributor resolution note') }}</h3>
                <p class="mt-2 whitespace-pre-line text-sm">{{ $report->resolution_note }}</p>
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('gallery.report.destroy', $report->recipe) }}" class="mt-6" onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Withdraw your report for :recipe? This cannot be undone.', ['recipe' => $report->recipe->name])) }})">
            @csrf
            @method('DELETE')
            <x-ui.button type="submit" variant="danger">{{ __('Withdraw Report') }}</x-ui.button>
        </form>
    </section>
</x-layouts.app>
