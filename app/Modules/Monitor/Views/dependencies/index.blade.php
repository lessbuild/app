@extends('monitor::layouts.app')

@section('title', 'Service map')
@section('breadcrumb', 'Service map')

@section('content')
    <div class="flex flex-col gap-6">
        <x-monitor::ui.page-header eyebrow="Observed topology" eyebrow-icon="server" title="Service map" description="Parent-child relationships from distributed traces. This describes observed traffic, not every possible dependency or a guaranteed request path.">
            <x-slot:actions>
            <form method="GET" action="{{ route('monitor.dependencies.index') }}" class="flex flex-wrap items-end gap-2">
                <x-monitor::ui.select name="range" label="Time range" :value="$filters['range']" :options="\App\Modules\Monitor\Services\Telemetry\ServiceDependencyMap::RANGES" :restore="false" hide-label />
                <x-monitor::ui.select name="environment" label="Environment" :value="isset($filters['environment']) ? (int) $filters['environment'] : null" :options="$environmentOptions" placeholder="All environments" :restore="false" hide-label />
                <x-monitor::ui.button variant="secondary">Refresh</x-monitor::ui.button>
            </form>
            </x-slot:actions>
        </x-monitor::ui.page-header>

        @if($map['truncated'])
            <x-signal.ui.alert as="p" tone="warning" class="block p-4 text-xs leading-5 text-warning dark:text-warning" role="status">This view is capped at 20,000 span records. The map may omit lower-volume relationships; narrow the time range or environment for a more complete view.</x-signal.ui.alert>
        @endif

        <section aria-label="Service map summary" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['label' => 'Services observed', 'value' => number_format($map['service_count']), 'caption' => 'Named span services in the window', 'icon' => 'server'],
                ['label' => 'Dependencies observed', 'value' => number_format($map['dependency_count']), 'caption' => 'Cross-service parent-child edges', 'icon' => 'activity'],
                ['label' => 'Traces represented', 'value' => number_format($map['traces']), 'caption' => 'Unique trace IDs in the view', 'icon' => 'activity'],
                ['label' => 'Span records', 'value' => number_format($map['records']), 'caption' => $map['truncated'] ? 'Capped sample of stored records' : 'Stored records examined', 'icon' => 'list'],
            ] as $stat)
                <x-signal.ui.card as="div" class="min-w-0 p-5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-control bg-surface-muted text-muted dark:bg-surface-muted dark:text-muted"><x-monitor::icon :name="$stat['icon']" class="h-[18px] w-[18px]" /></span>
                    <h2 class="mt-5 text-xs font-semibold text-muted dark:text-subtle">{{ $stat['label'] }}</h2>
                    <p class="mt-1 text-2xl font-bold tracking-tight text-ink dark:text-ink">{{ $stat['value'] }}</p>
                    <p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">{{ $stat['caption'] }}</p>
                </x-signal.ui.card>
            @endforeach
        </section>

        <x-signal.ui.panel as="section" class="overflow-hidden">
            <div class="border-b border-line p-5 sm:px-6 dark:border-line">
                <h2 class="text-base font-bold text-ink dark:text-ink">Observed dependencies</h2>
                <p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">A dependency is counted when a span's parent belongs to a different service. Latency is the child span duration, not total end-to-end latency.</p>
            </div>
            @if($map['edges'] !== [])
                <div>
                    <x-monitor::ui.table caption="Observed service dependencies and their recent health signals" :framed="false" table-class="min-w-[760px]">
                        <x-slot:head>
                            <tr><th scope="col" class="font-semibold sm:px-6">Dependency</th><th scope="col" class="text-right font-semibold">Calls</th><th scope="col" class="text-right font-semibold">Errors</th><th scope="col" class="text-right font-semibold">Average</th><th scope="col" class="text-right font-semibold">Max</th><th scope="col" class="text-right font-semibold sm:px-6">Last observed</th></tr>
                        </x-slot:head>
                            @foreach($map['edges'] as $edge)
                                <tr>
                                    <th scope="row" class="font-semibold text-ink sm:px-6"><span class="inline-flex max-w-[16rem] items-center gap-2 truncate"><span class="truncate">{{ $edge['source'] }}</span><x-monitor::icon name="arrow-up-right" class="h-3.5 w-3.5 shrink-0 text-subtle" /><span class="truncate">{{ $edge['target'] }}</span></span><span class="mt-1 block text-[11px] font-normal text-muted dark:text-subtle">{{ number_format($edge['trace_count']) }} {{ Str::plural('trace', $edge['trace_count']) }}</span></th>
                                    <td class="text-right font-semibold">{{ number_format($edge['calls']) }}</td>
                                    <td class="text-right"><x-monitor::ui.badge :tone="$edge['error_rate'] > 0 ? 'red' : 'slate'">{{ number_format($edge['error_rate'], 2) }}%</x-monitor::ui.badge><span class="mt-1 block text-[10px] text-muted dark:text-subtle">{{ number_format($edge['error_count']) }} failed</span></td>
                                    <td class="whitespace-nowrap text-right">{{ $edge['average_duration'] === null ? 'Not reported' : \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($edge['average_duration']) }}</td>
                                    <td class="whitespace-nowrap text-right">{{ $edge['max_duration'] === null ? 'Not reported' : \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($edge['max_duration']) }}</td>
                                    <td class="whitespace-nowrap text-right text-muted sm:px-6 dark:text-subtle">{{ $edge['last_seen']?->diffForHumans() ?? 'Not reported' }}<span class="mt-1 block text-[10px]">{{ $edge['last_seen']?->format('Y-m-d H:i:s.u').' UTC' }}</span></td>
                                </tr>
                            @endforeach
                    </x-monitor::ui.table>
                </div>
            @else
                <div class="flex min-h-56 flex-col items-center justify-center gap-2 p-6 text-center">
                    <x-monitor::icon name="server" class="h-8 w-8 text-subtle" />
                    <p class="text-sm font-semibold text-ink dark:text-ink">No cross-service relationships found.</p>
                    <p class="max-w-md text-xs leading-5 text-muted dark:text-subtle">Send distributed traces with parent span IDs and service names, then refresh. A single service or missing parent links will appear in the service list but cannot form an edge.</p>
                </div>
            @endif
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="overflow-hidden">
            <div class="border-b border-line p-5 sm:px-6 dark:border-line">
                <h2 class="text-base font-bold text-ink dark:text-ink">Services in this window</h2>
                <p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Error rate uses span records with an error severity or HTTP 500+ status. Missing durations are excluded from averages.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
                @forelse($map['services'] as $service)
                    <x-signal.ui.card as="div" class="shadow-none p-4">
                        <div class="flex items-start justify-between gap-3"><p class="min-w-0 truncate text-sm font-semibold text-ink dark:text-ink" title="{{ $service['name'] }}">{{ $service['name'] }}</p><x-monitor::ui.badge :tone="$service['error_rate'] > 0 ? 'red' : 'slate'">{{ number_format($service['error_rate'], 2) }}%</x-monitor::ui.badge></div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-xs"><div><dt class="text-muted dark:text-subtle">Spans</dt><dd class="mt-1 font-bold">{{ number_format($service['span_count']) }}</dd></div><div><dt class="text-muted dark:text-subtle">Average</dt><dd class="mt-1 font-bold">{{ $service['average_duration'] === null ? '—' : \App\Modules\Monitor\Data\Telemetry\TraceRecord::formatDuration($service['average_duration']) }}</dd></div></dl>
                        <p class="mt-4 text-[11px] text-muted dark:text-subtle">Last observed {{ $service['last_seen']?->diffForHumans() ?? 'not reported' }}</p>
                    </x-signal.ui.card>
                @empty
                    <p class="col-span-full py-8 text-center text-sm text-muted dark:text-subtle">No trace spans match this window.</p>
                @endforelse
            </div>
        </x-signal.ui.panel>

        <p class="text-xs leading-5 text-muted dark:text-subtle">Window: {{ $map['from']->format('Y-m-d H:i:s.u') }}–{{ $map['until']->format('Y-m-d H:i:s.u') }} UTC. This map is derived from received trace records and can be affected by sampling, clock skew, missing spans, duplicate IDs and retention.</p>
    </div>
@endsection
