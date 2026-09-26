@extends('monitor::layouts.app')

@section('title', 'Events & logs')
@section('breadcrumb', 'Events & logs')

@section('content')
    <div class="flex flex-col gap-6">
        <x-monitor::ui.page-header eyebrow="Investigation" title="Events & logs" description="Search received telemetry, inspect context and follow a record into its trace." class="sm:items-start">
            <x-slot:actions>
            <x-monitor::ui.button :href="route('monitor.events.index', $filters)" class="shrink-0 self-start focus-visible:outline-2 focus-visible:outline-primary" variant="secondary">Refresh results</x-monitor::ui.button>
            </x-slot:actions>
        </x-monitor::ui.page-header>

        <x-monitor::ui.filter-panel :action="route('monitor.events.index')">
            @if($release)
                <x-signal.ui.input type="hidden" name="release" value="{{ $release->id }}" :restore="false" />
                <p class="text-xs text-primary dark:text-primary">Release: <a href="{{ route('monitor.releases.show', $release) }}" class="font-bold hover:underline">{{ $release->version }} · {{ $release->serviceLabel() }}</a> · <a href="{{ route('monitor.events.index', array_diff_key($filters, ['release' => true, 'page' => true])) }}" class="underline">Remove release filter</a></p>
            @endif
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(180px,1fr)]">
                <div class="flex flex-col gap-2">
                    <x-monitor::ui.input name="q" label="Search text" :value="$filters['q'] ?? null" maxlength="255" placeholder="Message, operation, route, service or ID" aria-describedby="search-help" />
                    <p id="search-help" class="text-xs leading-5 text-muted dark:text-subtle">Searches names, routes, services, IDs, OTLP text bodies and payload.message / payload.body. % and _ are literal characters. Arbitrary attributes are not searched.</p>
                </div>
                <x-monitor::ui.select name="range" label="Event time range" :value="$filters['range']" :options="$rangeOptions" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-monitor::ui.select name="application" label="Application" :value="$filters['application'] ?? null" :options="$applications->pluck('name', 'id')->all()" placeholder="All applications" />
                <x-monitor::ui.select name="environment" label="Environment" :value="$filters['environment'] ?? null" :options="$environments->mapWithKeys(fn ($environment) => [$environment->id => $environment->application->name.' / '.$environment->name])->all()" placeholder="All environments" />
                <x-monitor::ui.select name="type" label="Event type" :value="$filters['type'] ?? null" :options="$typeOptions" placeholder="All types" />
                <x-monitor::ui.select name="severity" label="Reported severity" :value="$filters['severity'] ?? null" :options="$severityOptions" placeholder="All severities" />
            </div>
            <details @if(isset($filters['service']) || isset($filters['trace']) || isset($filters['has_trace']) || isset($filters['status']) || isset($filters['min_duration']) || $filters['range'] === 'custom') open @endif>
                <summary class="cursor-pointer text-xs font-semibold text-primary focus-visible:outline-2 focus-visible:outline-primary dark:text-primary">More filters & custom dates</summary>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <x-monitor::ui.input name="service" label="Service (exact)" :value="$filters['service'] ?? null" maxlength="100" />
                    <x-monitor::ui.input name="trace" label="Trace ID (exact)" :value="$filters['trace'] ?? null" maxlength="64" />
                    <x-monitor::ui.select name="has_trace" label="Trace context" :value="$filters['has_trace'] ?? null" :options="['yes' => 'With a trace ID', 'no' => 'Without a trace ID']" placeholder="Any trace context" />
                    <x-monitor::ui.input name="status" label="HTTP status" type="number" :value="$filters['status'] ?? null" min="100" max="599" step="1" />
                    <x-monitor::ui.input name="min_duration" label="Minimum duration (ms)" type="number" :value="$filters['min_duration'] ?? null" min="0" max="100000000000000" step="any" />
                    <x-monitor::ui.select name="sort" label="Sort records" :value="$filters['sort']" :options="$sortOptions" />
                    <x-monitor::ui.input name="from" label="Custom start · UTC, inclusive" type="datetime-local" :value="$filters['from'] ?? null" />
                    <x-monitor::ui.input name="to" label="Custom end · UTC, exclusive" type="datetime-local" :value="$filters['to'] ?? null" />
                </div>
                <p class="mt-3 text-xs text-muted dark:text-subtle">Custom dates apply only when “Custom UTC range” is selected. Filters use the reported event timestamp, not arrival time. Changing application may require clearing the environment selection.</p>
            </details>
            <div class="flex flex-wrap items-center gap-3">
                <x-monitor::ui.button>Search events</x-monitor::ui.button>
                <a href="{{ route('monitor.events.index') }}" class="rounded-control px-3 py-2 text-xs font-semibold text-muted hover:bg-surface-muted dark:text-subtle dark:hover:bg-surface-muted">Clear filters</a>
                <a href="{{ route('monitor.events.index', ['type' => 'log', 'range' => $filters['range'] === 'custom' ? '24h' : $filters['range']]) }}" class="rounded-control px-3 py-2 text-xs font-semibold text-primary hover:bg-primary-soft dark:text-primary dark:hover:bg-primary-soft">Logs only</a>
                <a href="{{ route('monitor.events.index', ['has_trace' => 'yes', 'range' => $filters['range'] === 'custom' ? '24h' : $filters['range']]) }}" class="rounded-control px-3 py-2 text-xs font-semibold text-primary hover:bg-primary-soft dark:text-primary dark:hover:bg-primary-soft">Find trace-linked events</a>
            </div>
        </x-monitor::ui.filter-panel>

        <x-signal.ui.panel as="section" aria-labelledby="results-heading" class="overflow-hidden">
            <div class="flex flex-col justify-between gap-2 border-b border-line p-5 sm:flex-row sm:items-center dark:border-line">
                <div>
                    <h2 id="results-heading" class="text-base font-bold">{{ number_format($events->total()) }} matching {{ Str::plural('record', $events->total()) }}</h2>
                    <p class="mt-1 text-xs text-muted dark:text-subtle">
                        @if($window[0])
                            {{ $window[0]->format('Y-m-d H:i:s') }} → {{ $window[1]->format('Y-m-d H:i:s') }} UTC
                        @else
                            All stored timestamps, including future timestamps reported by source clocks.
                        @endif
                    </p>
                </div>
                <p class="text-xs text-muted dark:text-subtle">50 records per page · refresh for new events</p>
            </div>
            @if($events->isEmpty())
                <div class="flex flex-col items-start gap-3 p-6">
                    <p class="text-sm font-semibold">No events match this view.</p>
                    <p class="max-w-xl text-sm leading-6 text-muted dark:text-subtle">Try a wider time range, clear a filter, or send telemetry from an application. Older events and timestamps ahead of the current time can be found under “All stored events”.</p>
                    <a href="{{ route('monitor.events.index', ['range' => 'all']) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">Search all stored events</a>
                </div>
            @else
                <div>
                    <x-monitor::ui.table caption="Matching telemetry events, ordered by {{ strtolower($sortOptions[$filters['sort']]) }}" :framed="false" table-class="min-w-[850px]">
                        <x-slot:head>
                            <tr><th scope="col" class="font-semibold">Event time (UTC)</th><th scope="col" class="font-semibold">Operation / message</th><th scope="col" class="font-semibold">Source</th><th scope="col" class="font-semibold">Duration / status</th><th scope="col" class="font-semibold">Trace</th></tr>
                        </x-slot:head>
                            @foreach($records as $record)
                                <tr class="hover:bg-surface-muted dark:hover:bg-surface-muted">
                                    <td class="whitespace-nowrap align-top text-muted dark:text-subtle"><time datetime="{{ $record->event->occurred_at->copy()->utc()->toIso8601String() }}">{{ $record->event->occurred_at->copy()->utc()->format('M j · H:i:s.u') }}</time></td>
                                    <td class="max-w-sm align-top">
                                        <a href="{{ route('monitor.events.show', ['event' => $record->event->id, ...$filters]) }}" class="block truncate font-semibold hover:text-primary focus-visible:outline-2 focus-visible:outline-primary dark:hover:text-primary" title="{{ $record->name() }}">{{ $record->name() }}</a>
                                        <div class="mt-2 flex flex-wrap gap-2"><x-monitor::ui.badge :tone="$record->tone()">{{ $record->event->type }}</x-monitor::ui.badge><span class="self-center text-[11px] text-muted dark:text-subtle">{{ $record->event->severity }}</span></div>
                                    </td>
                                    <td class="max-w-xs align-top">
                                        <p class="truncate font-semibold">{{ $record->service() }}</p>
                                        <p class="mt-1 truncate text-[11px] text-muted dark:text-subtle">{{ $record->event->environment->application->name }} / {{ $record->event->environment->name }}</p>
                                    </td>
                                    <td class="whitespace-nowrap align-top">
                                        <p class="font-mono">{{ $record->durationLabel() }}</p>
                                        <p class="mt-1 text-[11px] text-muted dark:text-subtle">{{ $record->event->status_code !== null ? 'HTTP '.$record->event->status_code : 'No HTTP status' }}</p>
                                    </td>
                                    <td class="align-top">
                                        @if(filled($record->event->trace_id))
                                            <a href="{{ route('monitor.traces.show', $record->event->trace_id) }}" class="block max-w-32 truncate font-mono font-semibold text-primary hover:underline dark:text-primary" title="{{ $record->event->trace_id }}">{{ $record->event->trace_id }}</a>
                                        @else
                                            <span class="text-subtle">No trace ID</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                    </x-monitor::ui.table>
                </div>
            @endif
        </x-signal.ui.panel>
        {{ $events->links() }}
    </div>
@endsection
