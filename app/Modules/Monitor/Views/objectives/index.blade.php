@extends('monitor::layouts.app')

@section('title', 'Service objectives')
@section('breadcrumb', 'SLOs')

@section('content')
    <div class="flex flex-col gap-6">
        <x-monitor::ui.page-header eyebrow="Reliability objectives" eyebrow-icon="shield" title="SLOs" description="Define the reliability customers should experience, then track the rolling error budget from recorded request telemetry.">
            @if($canManage)
                <x-slot:actions><x-monitor::ui.button :href="route('monitor.objectives.create')"><x-monitor::icon name="plus" class="h-4 w-4" />Create SLO</x-monitor::ui.button></x-slot:actions>
            @endif
        </x-monitor::ui.page-header>

        <x-signal.ui.alert as="p" tone="info" class="border-primary/30 bg-primary-soft block p-4 text-xs leading-5 text-primary dark:text-primary">SLOs are calculated on demand from request records in one environment. Missing status codes or durations are reported as unknown and do not count as healthy; sampling and incomplete instrumentation can make the result look better or worse than the customer experience.</x-signal.ui.alert>

        <x-signal.ui.panel as="section" class="overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-4 dark:border-line"><div><h2 class="text-base font-bold text-ink dark:text-ink">Configured objectives</h2><p class="mt-1 text-xs text-muted dark:text-subtle">{{ number_format($objectives->total()) }} {{ Str::plural('objective', $objectives->total()) }} · open one to calculate its current budget</p></div></div>
            <div class="overflow-x-auto">
                <x-monitor::ui.table caption="Configured service level objectives" :framed="false" table-class="min-w-[720px]">
                    <x-slot:head><tr><th scope="col" class="font-semibold">Objective / environment</th><th scope="col" class="font-semibold">Indicator</th><th scope="col" class="text-right font-semibold">Target</th><th scope="col" class="font-semibold">Window</th><th scope="col" class="text-right font-semibold">State</th></tr></x-slot:head>
                        @forelse($objectives as $objective)
                            <tr>
                                <th scope="row" class="max-w-sm"><a href="{{ route('monitor.objectives.show', $objective) }}" class="break-words text-sm font-bold text-primary hover:underline dark:text-primary">{{ $objective->name }}</a><p class="mt-1 text-[11px] font-normal text-muted dark:text-subtle">{{ $objective->environment->application->name }} / {{ $objective->environment->name }}</p><p class="mt-1 text-[11px] font-normal text-muted dark:text-subtle">{{ $objective->scopeLabel() }}</p></th>
                                <td>{{ $objective->indicatorLabel() }}@if($objective->isLatency())<span class="mt-1 block text-[11px] text-muted dark:text-subtle">≤ {{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($objective->latency_threshold_ms) }}</span>@else<span class="mt-1 block text-[11px] text-muted dark:text-subtle">HTTP {{ $objective->status_min }}–{{ $objective->status_max }}</span>@endif</td>
                                <td class="text-right font-bold">{{ number_format($objective->target, 3) }}%</td>
                                <td class="whitespace-nowrap">{{ $objective->windowLabel() }}</td>
                                <td class="text-right"><x-monitor::ui.badge :tone="$objective->enabled ? 'green' : 'slate'">{{ $objective->enabled ? 'Enabled' : 'Paused' }}</x-monitor::ui.badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-12 text-center text-muted dark:text-subtle">No SLOs yet. Start with an availability target for a production environment.</td></tr>
                        @endforelse
                </x-monitor::ui.table>
            </div>
            @if($objectives->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $objectives->links() }}</div>@endif
        </x-signal.ui.panel>
    </div>
@endsection
