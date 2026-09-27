@php($project = $overview->project)
@php($duration = \App\Data\Telemetry\TraceRecord::formatDuration($timeline['duration']))

<x-signal.layouts.project :overview="$overview" :title="$timeline['title']" :description="__('Trace :id', ['id' => $traceId])">
    <div class="grid gap-4 sm:grid-cols-4">
        <x-signal.ui.stat :label="__('Duration')" :value="$duration" />
        <x-signal.ui.stat :label="__('Spans')" :value="number_format($timeline['spanCount'])" />
        <x-signal.ui.stat :label="__('Other events')" :value="number_format($timeline['eventCount'])" />
        <x-signal.ui.stat :label="__('Errors')" :value="number_format($timeline['errorCount'])" />
    </div>

    <x-signal.ui.card class="overflow-x-auto p-4 sm:p-6">
        <div class="min-w-[640px]">
            <div class="mb-2 grid grid-cols-[minmax(200px,1fr)_minmax(260px,1.6fr)_96px] gap-4 text-xs text-muted">
                <span>{{ __('Operation') }}</span>
                <span class="flex justify-between"><span>0 ms</span><span>{{ $duration }}</span></span>
                <span class="text-right">{{ __('Duration') }}</span>
            </div>
            <ol class="grid gap-1" aria-label="{{ __('Trace timeline') }}">
                @foreach ($timeline['rows'] as $row)
                    @php($record = $row['record'])
                    <li>
                        <a href="{{ route('monitoring.events.show', [$project, $record->event->id]) }}" class="grid grid-cols-[minmax(200px,1fr)_minmax(260px,1.6fr)_96px] items-center gap-4 rounded-control px-2 py-2 hover:bg-surface-muted">
                            <span class="min-w-0" style="padding-left: {{ min(12, $row['depth']) * 14 }}px">
                                <span class="block truncate text-sm font-semibold text-ink">{{ $record->name() }}</span>
                                <span class="block truncate text-xs text-muted">{{ $record->service() }}@foreach ($row['notes'] as $note) · {{ __($note) }}@endforeach</span>
                            </span>
                            <span class="relative h-3 rounded-full bg-surface-muted" aria-hidden="true">
                                <span @class(['absolute top-0 h-3 rounded-full', 'bg-[var(--ui-danger)]' => $record->hasError, 'bg-[var(--ui-warning)]' => ! $record->hasError && $record->hasWarning, 'bg-[var(--ui-chart-1)]' => ! $record->hasError && ! $record->hasWarning])
                                    style="left: {{ $row['left'] }}%; width: max(2px, {{ $row['width'] }}%)"></span>
                            </span>
                            <span class="text-right text-xs text-muted">{{ $record->durationLabel() }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </div>
    </x-signal.ui.card>
    <p class="text-xs text-muted">{{ __('Only received spans appear; sampled or missing spans leave gaps. Indentation follows parent spans.') }}</p>
</x-signal.layouts.project>
