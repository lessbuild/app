<x-signal.layouts.platform
    :title="__('Analytics data and processing')"
    :description="__('Review report exports, event processing, and workspace data export.')"
    :navigation="$navigation"
    :account-user="$accountUser"
    :current-workspace="$currentWorkspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Data and processing')" :description="__('Review recent export and ingestion processing without exposing internal job details.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.analytics.sites.index', $workspace)" variant="secondary">{{ __('Analytics sites') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    @if (! $snapshot->available)<x-signal.ui.alert class="mt-5" tone="warning" role="status">{{ __('Analytics processing history is temporarily unavailable.') }}</x-signal.ui.alert>@endif
    @if (session('status'))<x-signal.ui.alert class="mt-5" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>@endif

    @if ($snapshot->canExportWorkspace)
        <x-signal.ui.card class="mt-6 flex flex-wrap items-center justify-between gap-4 p-5">
            <div><h2 class="font-extrabold">{{ __('Workspace data export') }}</h2><p class="mt-1 text-sm leading-6 text-muted">{{ __('Download a streaming NDJSON export of authorized Analytics workspace records. Verification tokens, report download tokens, file paths, and processing failures are excluded.') }}</p></div>
            <x-signal.ui.button :href="route('core.workspace.analytics.data.export', $workspace)" variant="primary">{{ __('Download workspace export') }}</x-signal.ui.button>
        </x-signal.ui.card>
    @endif

    <section class="mt-8" aria-labelledby="report-export-heading">
        <h2 id="report-export-heading" class="mb-3 text-lg font-extrabold">{{ __('CSV report exports') }}</h2>
        @if ($snapshot->reports === [])<x-signal.ui.empty-state :title="__('No report exports yet')" :description="__('Create a filtered report export from a site settings page.')" icon="arrow-down-tray" />@else
            <x-signal.ui.table :caption="__('Analytics report exports')">
                <x-slot:head><tr><th scope="col">{{ __('Site') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Requested') }}</th><th scope="col">{{ __('Expires') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></x-slot:head>
                @foreach ($snapshot->reports as $report)<tr><th scope="row"><div class="font-bold">{{ $report->siteName }}</div><div class="mt-1 text-xs text-muted">{{ __('Filters: :filters', ['filters' => collect($report->filters)->only(['days','path','source','campaign','device'])->map(fn ($value, $key) => $key.': '.$value)->join(' · ')]) }}</div></th><td><x-signal.ui.badge :tone="match($report->status) {'completed' => 'success', 'failed' => 'danger', 'processing' => 'info', default => 'neutral'}">{{ ucfirst($report->status) }}</x-signal.ui.badge></td><td>{{ $report->createdAt->diffForHumans() }}</td><td>{{ $report->expiresAt?->diffForHumans() ?? ($report->downloadAvailable ? __('No expiry under current plan') : __('Unavailable')) }}</td><td><div class="flex justify-end gap-2">@if ($report->downloadAvailable)<x-signal.ui.button :href="route('core.workspace.analytics.reports.download', [$workspace, $report->siteId, $report->id])" variant="secondary" class="ui-btn-sm">{{ __('Download') }}</x-signal.ui.button>@endif @if ($report->canRetry)<form method="POST" action="{{ route('core.workspace.analytics.reports.retry', [$workspace, $report->siteId, $report->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" class="ui-btn-sm">{{ __('Retry') }}</x-signal.ui.button></form>@endif</div></td></tr>@endforeach
            </x-signal.ui.table>
        @endif
    </section>

    <section class="mt-8" aria-labelledby="processing-heading">
        <h2 id="processing-heading" class="mb-3 text-lg font-extrabold">{{ __('Event processing') }}</h2>
        @if ($snapshot->processing === [])<x-signal.ui.empty-state :title="__('No recent processing batches')" :description="__('Accepted event batches will appear here after a tracker receives data.')" icon="clock" />@else
            <x-signal.ui.table :caption="__('Recent Analytics event processing')">
                <x-slot:head><tr><th scope="col">{{ __('Site') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Accepted events') }}</th><th scope="col">{{ __('Accepted') }}</th><th scope="col">{{ __('Processed') }}</th></tr></x-slot:head>
                @foreach ($snapshot->processing as $batch)<tr><th scope="row">{{ $batch->siteName }}</th><td><x-signal.ui.badge :tone="match($batch->status) {'processed' => 'success', 'failed' => 'danger', 'processing' => 'info', default => 'neutral'}">{{ ucfirst($batch->status) }}</x-signal.ui.badge></td><td>{{ $batch->acceptedEventCount }}</td><td>{{ $batch->acceptedAt->diffForHumans() }}</td><td>{{ $batch->processedAt?->diffForHumans() ?? __('Pending') }}</td></tr>@endforeach
            </x-signal.ui.table>
        @endif
        <p class="mt-3 text-xs text-muted">{{ __('Processing failures and internal batch identifiers are kept private. Failed batches are not retried from this page.') }}</p>
    </section>
</x-signal.layouts.platform>
