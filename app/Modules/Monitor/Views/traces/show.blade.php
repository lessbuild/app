@extends('monitor::layouts.app')

@section('title', 'Trace')
@section('breadcrumb', 'Trace')

@section('content')
    <div class="flex flex-col gap-6">
        <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-xs text-muted dark:text-subtle">
            <a href="{{ route('monitor.dashboard') }}" class="hover:text-primary dark:hover:text-primary">Overview</a>
            <x-monitor::icon name="chevron-right" class="h-3.5 w-3.5" /><span>Trace investigation</span>
        </nav>
        <x-monitor::ui.page-header eyebrow="Distributed trace" eyebrow-icon="activity" :title="$timeline['title']">
            <x-slot:metadata>
                <code id="trace-id" class="min-w-0 break-all text-xs text-muted">{{ $traceId }}</code>
                <x-monitor::ui.button type="button" variant="secondary" data-copy-target="trace-id" class="shrink-0" aria-label="Copy trace ID"><span data-copy-label>Copy ID</span></x-monitor::ui.button>
            </x-slot:metadata>
            <x-slot:actions>
                @if($timeline['errorCount'] > 0)
                    <x-monitor::ui.badge tone="red">{{ $timeline['errorCount'] }} {{ Str::plural('error', $timeline['errorCount']) }} reported in view</x-monitor::ui.badge>
                @elseif($timeline['warningCount'] > 0)
                    <x-monitor::ui.badge tone="amber">{{ $timeline['warningCount'] }} {{ Str::plural('warning', $timeline['warningCount']) }} reported in view</x-monitor::ui.badge>
                @else
                    <x-monitor::ui.badge>No errors reported in view</x-monitor::ui.badge>
                @endif
            </x-slot:actions>
        </x-monitor::ui.page-header>
        <form method="GET" action="{{ route('monitor.traces.show', $traceId) }}" class="flex flex-wrap items-end gap-3">
            <x-monitor::ui.select name="environment" label="Environment" :value="$environmentId" placeholder="All environments with this trace ID" :options="$environments->mapWithKeys(fn ($environment): array => [$environment->id => $environment->application->name.' / '.$environment->name])->all()" :restore="false" />
            <x-monitor::ui.button variant="secondary">Apply filter</x-monitor::ui.button>
        </form>
        @if($environments->count() > 1 && $environmentId === null)
            <p class="ui-alert border-info/30 bg-info-soft block p-4 text-xs leading-5 text-info dark:text-info">
                This trace ID appears in {{ $environments->count() }} environments. Cross-service spans are shown together; filter an environment if unrelated applications reuse trace IDs.
            </p>
        @endif
        @if($events->hasPages())
            <p class="ui-alert ui-alert-warning block p-4 text-xs leading-5 text-warning dark:text-warning" role="status">
                Partial trace view: showing records {{ number_format($events->firstItem()) }}–{{ number_format($events->lastItem()) }} of {{ number_format($events->total()) }}.
                Timing, counts, services and parent links below describe this page only. Use the page links to investigate the remaining records.
            </p>
        @endif
        <section aria-label="Trace summary" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="ui-card p-5">
                <h2 class="text-xs font-semibold text-muted dark:text-subtle">Recorded window{{ $events->hasPages() ? ' · this page' : '' }}</h2>
                <p class="mt-2 wrap-anywhere text-2xl font-bold">{{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($timeline['duration']) }}</p>
                <p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">Earliest start to latest reported end. Overlapping durations are not added together.</p>
            </div>
            <div class="ui-card p-5">
                <h2 class="text-xs font-semibold text-muted dark:text-subtle">Span records in view</h2>
                <p class="mt-2 text-2xl font-bold">{{ number_format($timeline['spanCount']) }}</p>
                <p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">{{ number_format($timeline['eventCount']) }} correlated {{ Str::plural('event', $timeline['eventCount']) }} · {{ $timeline['serviceCount'] }} named {{ Str::plural('service', $timeline['serviceCount']) }}</p>
            </div>
            <div class="ui-card p-5">
                <h2 class="text-xs font-semibold text-muted dark:text-subtle">Records matching filter</h2>
                <p class="mt-2 text-2xl font-bold">{{ number_format($events->total()) }}</p>
                <p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">Only received data is available. Missing or sampled spans may not appear.</p>
            </div>
            <div class="ui-card p-5">
                <h2 class="text-xs font-semibold text-muted dark:text-subtle">First timestamp in view</h2>
                <p class="mt-2 text-sm font-bold">{{ $timeline['first']->event->occurred_at->copy()->utc()->format('M j, Y · H:i:s.u') }} UTC</p>
                <p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">Offsets use source nanoseconds when supplied; otherwise the stored timestamp.</p>
            </div>
        </section>
        <section aria-labelledby="waterfall-title" class="ui-panel overflow-hidden">
            <div class="border-b border-line p-5 sm:px-6 dark:border-line">
                <h2 id="waterfall-title" class="text-base font-bold">Trace waterfall</h2>
                <p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Select a record to inspect its attributes and payload. Indentation shows parent links. Thin markers indicate zero or unreported duration.</p>
            </div>
            <div class="relative overflow-x-auto p-5 sm:p-6" tabindex="0" aria-label="Scrollable trace waterfall">
                <div class="min-w-[720px]">
                    <div class="mb-3 grid grid-cols-[minmax(240px,1fr)_minmax(280px,1.6fr)_110px] items-center gap-4 text-[11px] text-muted dark:text-subtle">
                        <span>Operation / service</span>
                        <div class="flex justify-between gap-2"><span>0 ms</span><span>{{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($timeline['duration'] / 2) }}</span><span>{{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($timeline['duration']) }}</span></div>
                        <span class="text-right">Duration</span>
                    </div>
                    <ol class="flex flex-col gap-1">
                        @foreach($timeline['rows'] as $row)
                            @php($record = $row['record'])
                            <li>
                                <a href="{{ route('monitor.traces.events.show', ['trace' => $traceId, 'event' => $record->event->id, 'environment' => $environmentId, 'page' => $events->currentPage()]) }}" class="grid grid-cols-[minmax(240px,1fr)_minmax(280px,1.6fr)_110px] items-center gap-4 rounded-control px-2 py-3 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-surface-muted" aria-label="Inspect {{ $record->name() }}">
                                    <div class="min-w-0" style="padding-left: {{ min($row['depth'], 6) * 12 }}px">
                                        <div class="flex items-center gap-2">
                                            <x-monitor::ui.badge :tone="$record->tone()">{{ $record->isSpan ? 'span' : $record->event->type }}</x-monitor::ui.badge>
                                            <span class="truncate text-xs font-semibold" title="{{ $record->name() }}">{{ $record->name() }}</span>
                                        </div>
                                        <p class="mt-1 truncate text-[11px] text-muted dark:text-subtle">{{ $record->service() }} · {{ $record->event->environment->application->name }} / {{ $record->event->environment->name }}</p>
                                        @foreach($row['notes'] as $note)
                                            <p class="mt-1 text-[11px] text-warning dark:text-warning">{{ $note }}</p>
                                        @endforeach
                                    </div>
                                    <div class="relative h-8 overflow-hidden rounded-control bg-surface-muted dark:bg-surface-muted" aria-hidden="true">
                                        <span @class([
                                            'absolute inset-y-1.5 min-w-px rounded-sm',
                                            'bg-danger' => $record->hasError,
                                            'bg-warning' => ! $record->hasError && $record->hasWarning,
                                            'bg-primary-soft' => ! $record->hasError && ! $record->hasWarning && $record->isSpan,
                                            'bg-info' => ! $record->hasError && ! $record->hasWarning && ! $record->isSpan,
                                        ]) style="left: min({{ $row['left'] }}%, calc(100% - 1px)); width: {{ $row['width'] }}%"></span>
                                    </div>
                                    <div class="text-right text-[11px] text-muted dark:text-subtle">
                                        <span class="block font-semibold">{{ $record->durationLabel() }}</span>
                                        <span class="mt-1 block">+{{ \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($row['offset']) }}</span>
                                        <span class="sr-only">from first timestamp; nesting depth {{ $row['depth'] }}</span>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
            <div class="flex flex-col gap-2 border-t border-line p-5 dark:border-line">
                @if($timeline['missingDurationCount'] > 0)
                    <p class="text-xs leading-5 text-muted dark:text-subtle">{{ $timeline['missingDurationCount'] }} {{ Str::plural('record', $timeline['missingDurationCount']) }} {{ $timeline['missingDurationCount'] === 1 ? 'has' : 'have' }} no valid duration; the window includes their timestamps only.</p>
                @endif
                <p class="text-xs leading-5 text-muted dark:text-subtle">Times follow source clocks. Clock skew, missing spans and older data with lower timestamp precision can affect the picture. This is not a service health assessment.</p>
            </div>
        </section>
        {{ $events->links() }}
        <section aria-labelledby="services-title" class="ui-panel p-5 sm:p-6">
            <h2 id="services-title" class="text-base font-bold">Services in this view</h2>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($timeline['services'] as $service => $records)
                    <div class="ui-card shadow-none flex items-center gap-3 p-4">
                        <x-monitor::icon name="server" class="h-5 w-5 shrink-0 text-primary" />
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold">{{ $service }}</p>
                            <p class="mt-1 text-[11px] text-muted dark:text-subtle">{{ $records->count() }} {{ Str::plural('record', $records->count()) }} · {{ $records->filter(fn ($record) => $record->hasError)->count() }} errors reported</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
@endsection
