@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Dashboards')" :description="__('Saved views of telemetry, incidents, monitors and SLOs across every project in :account.', ['account' => $project->account->name])">
    @include('telemetry._metrics-tabs')

    @if ($canManage)
        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($limit !== null)
                <span class="text-sm text-muted">{{ __(':used of :limit dashboards on your plan', ['used' => $dashboards->count(), 'limit' => $limit]) }}</span>
            @endif
            <x-signal.ui.button :href="route('monitoring.dashboards.create', $project)" variant="primary">{{ __('Add a dashboard') }}</x-signal.ui.button>
        </div>
    @endif

    @if ($dashboards->isEmpty())
        <x-signal.ui.empty-state icon="chart" :title="__('No dashboards yet')" :description="__('Bring telemetry, incidents, monitor health and SLOs together on one page your team can share.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Dashboards') }}">
                @foreach ($dashboards as $dashboard)
                    <li class="px-5 py-4">
                        <a href="{{ route('monitoring.dashboards.show', [$project, $dashboard->id]) }}" class="font-extrabold text-ink hover:underline">{{ $dashboard->name }}</a>
                        <p class="mt-0.5 text-xs text-muted">{{ trans_choice(':count widget|:count widgets', $dashboard->widgets_count ?? 0, ['count' => $dashboard->widgets_count ?? 0]) }} · {{ __(\App\Queries\Telemetry\TelemetrySummaryQuery::RANGES[$dashboard->range] ?? '') }}@if ($dashboard->creator) · {{ __('by :name', ['name' => $dashboard->creator->name]) }}@endif</p>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.project>
