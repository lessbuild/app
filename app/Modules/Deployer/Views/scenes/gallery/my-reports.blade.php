<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('gallery.index')" :title="__('Back to gallery')" />

    <x-signal.ui.page-header
        :title="__('My Community Reports')"
        :description="__('Review every report you submitted, including reports for recipes that are no longer published.')"
    >
        @if ($metrics['unread_updates'] > 0)
            <x-slot:actions>
                <form method="POST" action="{{ route('gallery.reports.mine.review-updates') }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">
                        {{ trans_choice('Review :count update|Review all :count updates', $metrics['unread_updates'], ['count' => $metrics['unread_updates']]) }}
                    </x-signal.ui.button>
                </form>
            </x-slot:actions>
        @endif
    </x-signal.ui.page-header>

    <x-signal.ui.local-nav class="mt-6" :label="__('Report history sections')">
        <a href="#gallery-report-history-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#gallery-report-history-filters" class="ui-local-nav__link">{{ __('Filters') }}</a>
        <a href="#gallery-report-history" class="ui-local-nav__link">{{ __('Reports') }}</a>
    </x-signal.ui.local-nav>

    @php
        $reportHistoryFilterCount = collect($filters)->filter(fn ($value, $key) => filled($value)
            && ($key === 'status' || $key === 'availability' || $key === 'updates'
                ? $value !== 'all'
                : ($key === 'sort' ? $value !== 'newest' : true)))->count();
        $reportStatusDialogQuery = request()->query('dialog');
        $reportStatusDialogId = is_string($reportStatusDialogQuery)
            && preg_match('/^report-status-(\d+)$/', $reportStatusDialogQuery, $reportStatusMatches) === 1
            ? (int) $reportStatusMatches[1]
            : null;
        $reportStatusDialogReport = $reportStatusDialogId === null
            ? null
            : $reports->getCollection()->first(fn ($report) => (int) $report->id === $reportStatusDialogId);
        $reportStatusDialogOpen = $reportStatusDialogReport !== null
            && $reportStatusDialogQuery === 'report-status-'.$reportStatusDialogReport->id;
    @endphp

    <x-signal.ui.filter-panel
        id="gallery-report-history-filters"
        class="mt-6 scroll-mt-24"
        :open="$reportHistoryFilterCount > 0"
        :summary="$reportHistoryFilterCount > 0 ? trans_choice(':count active filter|:count active filters', $reportHistoryFilterCount, ['count' => $reportHistoryFilterCount]) : null"
    >
        <form method="GET" action="{{ route('gallery.reports.mine') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div>
                <label for="search" class="ui-label">{{ __('Recipe') }}</label>
                <x-signal.ui.input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="{{ __('Recipe name') }}" class="ui-input" :restore="false" />
            </div>
            <div>
                <label for="status" class="ui-label">{{ __('Report status') }}</label>
                <x-signal.ui.select id="status" name="status" class="ui-input">
                    <option value="all" @selected($filters['status'] === 'all')>{{ __('All statuses') }}</option>
                    <option value="open" @selected($filters['status'] === 'open')>{{ __('Needs contributor review') }}</option>
                    <option value="resolved" @selected($filters['status'] === 'resolved')>{{ __('Resolved by contributor') }}</option>
                </x-signal.ui.select>
            </div>
            <div>
                <label for="availability" class="ui-label">{{ __('Recipe availability') }}</label>
                <x-signal.ui.select id="availability" name="availability" class="ui-input">
                    <option value="all" @selected($filters['availability'] === 'all')>{{ __('Published and unpublished') }}</option>
                    <option value="published" @selected($filters['availability'] === 'published')>{{ __('Published') }}</option>
                    <option value="unpublished" @selected($filters['availability'] === 'unpublished')>{{ __('No longer published') }}</option>
                </x-signal.ui.select>
            </div>
            <div>
                <label for="updates" class="ui-label">{{ __('Contributor updates') }}</label>
                <x-signal.ui.select id="updates" name="updates" class="ui-input">
                    <option value="all" @selected($filters['updates'] === 'all')>{{ __('Reviewed and unread') }}</option>
                    <option value="unread" @selected($filters['updates'] === 'unread')>{{ __('Unread updates') }}</option>
                    <option value="reviewed" @selected($filters['updates'] === 'reviewed')>{{ __('No unread update') }}</option>
                </x-signal.ui.select>
            </div>
            <div>
                <label for="reason" class="ui-label">{{ __('Issue type') }}</label>
                <x-signal.ui.select id="reason" name="reason" class="ui-input">
                    <option value="">{{ __('All issue types') }}</option>
                    @foreach (\App\Modules\Deployer\Models\RecipeReport::REASONS as $reason)
                        <option value="{{ $reason }}" @selected($filters['reason'] === $reason)>{{ str($reason)->headline() }}</option>
                    @endforeach
                </x-signal.ui.select>
            </div>
            <div>
                <label for="sort" class="ui-label">{{ __('Sort') }}</label>
                <x-signal.ui.select id="sort" name="sort" class="ui-input">
                    <option value="newest" @selected($filters['sort'] === 'newest')>{{ __('Newest reports') }}</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>{{ __('Oldest reports') }}</option>
                    <option value="updated" @selected($filters['sort'] === 'updated')>{{ __('Recently updated') }}</option>
                </x-signal.ui.select>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            <x-signal.ui.button href="{{ route('gallery.reports.mine.export', array_filter($filters, fn ($value) => $value !== null)) }}" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
            @if ($filters['search'] || $filters['status'] !== 'all' || $filters['availability'] !== 'all' || $filters['updates'] !== 'all' || $filters['reason'] || $filters['sort'] !== 'newest')
                <x-signal.ui.button href="{{ route('gallery.reports.mine') }}" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            @endif
        </div>
        </form>
    </x-signal.ui.filter-panel>

    <x-signal.ui.insights
        id="gallery-report-history-insights"
        class="mt-6 scroll-mt-24"
        :open="false"
        :summary="trans_choice(':count report|:count reports', $metrics['matching'], ['count' => $metrics['matching']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ([
                ['label' => __('Matching reports'), 'value' => $metrics['matching']],
                ['label' => __('Needs review'), 'value' => $metrics['open']],
                ['label' => __('Resolved'), 'value' => $metrics['resolved']],
                ['label' => __('No longer published'), 'value' => $metrics['unpublished']],
                ['label' => __('Unread updates'), 'value' => $metrics['unread_updates']],
            ] as $metric)
                <x-signal.ui.stat class="ui-card" :label="$metric['label']" :value="$metric['value']" />
            @endforeach
        </dl>
    </x-signal.ui.insights>

    @if ($reports->isEmpty())
        <div class="mx-auto mt-6 max-w-3xl">
            <x-lists.empty
                :title="__('No reports match these filters')"
                :description="__('Try changing or clearing the filters, or browse the gallery to report a recipe issue.')"
            />
        </div>
    @else
        <div id="gallery-report-history" class="mt-6 scroll-mt-24 space-y-4">
            @foreach ($reports as $report)
                @php
                    $unreadUpdate = $unreadUpdates->get($report->id);
                    $reportStatusDialogKey = 'report-status-'.$report->id;
                    $reportStatusUrl = route('gallery.report.status', $report);
                    $reportStatusContentUrl = route('gallery.report.status', ['report' => $report, 'fragment' => 'report-status']);
                    $reportStatusHistoryUrl = route('gallery.reports.mine', array_filter([
                        ...$filters,
                        'page' => request()->query('page'),
                        'dialog' => $reportStatusDialogKey,
                    ], fn ($value) => $value !== null));
                    $reportStatusIsOpen = $reportStatusDialogOpen && $reportStatusDialogReport->id === $report->id;
                @endphp
                <x-signal.ui.card @class([
                    'p-5',
                    'ui-card--unread' => $unreadUpdate,
                    'border-line' => ! $unreadUpdate,
                ])>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-signal.ui.badge tone="accent">{{ str($report->reason)->headline() }}</x-signal.ui.badge>
                                <x-signal.ui.badge :tone="$report->resolved_at === null ? 'danger' : 'success'">{{ $report->resolved_at === null ? __('Needs contributor review') : __('Resolved by contributor') }}</x-signal.ui.badge>
                                @if (! $report->recipe->is_published || $report->recipe->published_at === null)
                                    <x-signal.ui.badge tone="warning">{{ __('No longer published') }}</x-signal.ui.badge>
                                @endif
                                @if ($unreadUpdate)
                                    <x-signal.ui.badge tone="accent">{{ __('New update') }}</x-signal.ui.badge>
                                @endif
                            </div>
                            <h2 class="mt-3 text-lg font-bold text-ink">
                                <a
                                    href="{{ $reportStatusUrl }}"
                                    data-modal-trigger="gallery-report-status-dialog"
                                    data-modal-content-url="{{ $reportStatusContentUrl }}"
                                    data-modal-history-url="{{ $reportStatusHistoryUrl }}"
                                    aria-controls="gallery-report-status-dialog"
                                    aria-expanded="{{ $reportStatusIsOpen ? 'true' : 'false' }}"
                                    class="underline-offset-2 hover:underline"
                                >{{ $report->recipe->name }}</a>
                            </h2>
                            <p class="mt-1 text-sm text-muted">{{ str($report->recipe->category)->headline() }}</p>
                        </div>
                        <div class="text-right text-xs text-muted">
                            <span class="block">{{ __('Reported :date', ['date' => $report->created_at->diffForHumans()]) }}</span>
                            <span class="mt-1 block">{{ __('Updated :date', ['date' => $report->updated_at->diffForHumans()]) }}</span>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        @if ($unreadUpdate)
                            <form method="POST" action="{{ route('notifications.read', $unreadUpdate) }}">
                                @csrf
                                <x-signal.ui.button type="submit" variant="primary">{{ __('Review new update') }}</x-signal.ui.button>
                            </form>
                        @else
                            <x-signal.ui.button
                                :href="$reportStatusUrl"
                                data-modal-trigger="gallery-report-status-dialog"
                                data-modal-content-url="{{ $reportStatusContentUrl }}"
                                data-modal-history-url="{{ $reportStatusHistoryUrl }}"
                                aria-controls="gallery-report-status-dialog"
                                aria-expanded="{{ $reportStatusIsOpen ? 'true' : 'false' }}"
                                variant="secondary"
                            >{{ __('View report status') }}</x-signal.ui.button>
                        @endif
                    </div>
                </x-signal.ui.card>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $reports->links() }}
        </div>

        <x-dialogs.modal
            id="gallery-report-status-dialog"
            :title="__('Report status')"
            :description="__('Review the current report state without leaving your filtered report history.')"
            :open="$reportStatusDialogOpen"
        >
            <div data-modal-content>
                <div class="space-y-3 text-sm text-muted">{{ __('Loading report status…') }}</div>
            </div>
        </x-dialogs.modal>
    @endif
</x-layouts.app>
