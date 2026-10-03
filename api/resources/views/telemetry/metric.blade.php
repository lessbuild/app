@php($project = $overview->project)
@php($valid = collect($chart['points'])->filter(fn ($point) => $point['value'] !== null))
@php($polyline = $valid->map(fn ($point) => $point['x'].','.$point['y'])->implode(' '))
@php($number = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.'))

<x-signal.layouts.project :overview="$overview" :title="$series->name" :description="$series->environment->name.' · '.$series->resource_label.' · '.($series->unit ?: __('unitless')).' · '.$series->kind.($series->temporality ? ' / '.$series->temporality : '')">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <form method="GET" action="{{ route('monitoring.metrics.show', [$project, $series->id]) }}" class="flex flex-wrap items-end gap-3">
            <x-signal.ui.select-field name="range" :label="__('Range')">
                @foreach ($rangeOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['range'] === $value)>{{ __($label) }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="mode" :label="__('Show')">
                <option value="value" @selected($filters['mode'] === 'value')>{{ __('Recorded value') }}</option>
                @if ($series->supportsRate())
                    <option value="rate" @selected($filters['mode'] === 'rate')>{{ __('Rate per second') }}</option>
                @endif
            </x-signal.ui.select-field>
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Update') }}</x-signal.ui.button>
        </form>
        <div class="flex flex-wrap gap-2">
            <x-signal.ui.button :href="route('monitoring.metrics', $project)" variant="quiet">{{ __('All metrics') }}</x-signal.ui.button>
            @if ($canManage)
                <x-signal.ui.button :href="route('monitoring.rules.create', ['project' => $project, 'metric' => 'numeric_metric', 'series' => $series->id])" variant="primary">{{ __('Create alert') }}</x-signal.ui.button>
            @endif
        </div>
    </div>

    <x-signal.ui.card class="p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-extrabold text-ink">{{ $filters['mode'] === 'rate' ? __('Rate over time') : __('Value over time') }}</h2>
                <p class="mt-0.5 text-xs text-muted">{{ trans_choice(':count usable point|:count usable points', $chart['valid'], ['count' => number_format($chart['valid'])]) }} · {{ $from->format('Y-m-d H:i') }} – {{ $until->format('Y-m-d H:i') }} UTC</p>
            </div>
            @if ($chart['truncated'])<x-signal.ui.badge tone="warning">{{ __('Showing the latest :count points', ['count' => \App\Services\Monitoring\MetricChart::LIMIT]) }}</x-signal.ui.badge>@endif
        </div>
        @if ($polyline !== '')
            <div class="mt-5 overflow-x-auto rounded-control bg-surface-muted p-3">
                <svg viewBox="0 0 1000 200" class="h-64 w-full min-w-[640px]" role="img" aria-label="{{ __(':metric from :min to :max; latest :latest', ['metric' => $series->name, 'min' => $number($chart['minimum']), 'max' => $number($chart['maximum']), 'latest' => $number($chart['latest']['value'] ?? null)]) }}">
                    @foreach ([20, 100, 180] as $y)<line x1="0" y1="{{ $y }}" x2="1000" y2="{{ $y }}" class="stroke-line" stroke-width="1" />@endforeach
                    <polyline points="{{ $polyline }}" fill="none" class="stroke-chart-1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    @foreach ($valid as $point)
                        @php($unusual = $anomalies && ($point['anomaly']['state'] ?? null) === 'anomaly')
                        <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ $unusual ? 5 : 8 }}" @class(['fill-warning stroke-surface' => $unusual, 'fill-transparent' => ! $unusual]) stroke-width="2"><title>{{ \Carbon\CarbonImmutable::parse($point['time'])->format('Y-m-d H:i:s') }} UTC · {{ $number($point['value']) }}{{ $unusual ? ' · '.__('unusual :direction shift', ['direction' => $point['anomaly']['direction'] ?? '']) : '' }}</title></circle>
                    @endforeach
                </svg>
            </div>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-muted">{{ __('Lowest') }}</dt><dd class="mt-1 font-bold text-ink">{{ $number($chart['minimum']) }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('Highest') }}</dt><dd class="mt-1 font-bold text-ink">{{ $number($chart['maximum']) }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('Latest') }}</dt><dd class="mt-1 font-bold text-ink">{{ $number($chart['latest']['value'] ?? null) }}</dd></div>
            </dl>
        @else
            <p class="mt-5 rounded-control bg-surface-muted p-8 text-center text-sm text-muted">{{ __('No usable points in this range. Missing values, unsupported distributions and counter resets are listed below.') }}</p>
        @endif
    </x-signal.ui.card>

    @if ($anomalies)
        <x-signal.ui.alert :tone="$chart['anomalies'] > 0 ? 'warning' : 'info'">
            <div>
                <p class="font-bold">{{ $chart['anomalies'] > 0 ? trans_choice(':count unusual point|:count unusual points', $chart['anomalies'], ['count' => $chart['anomalies']]) : __('No unusual shifts') }}</p>
                <p class="mt-1 text-sm">
                    @if ($chart['anomalies'] > 0)
                        {{ __('The largest shift scored :score× the rolling deviation threshold. Treat marked points as leads, not incidents.', ['score' => number_format($chart['max_anomaly_score'], 2)]) }}
                    @elseif ($chart['anomaly_baseline_points'] < \App\Services\Monitoring\MetricAnomalyDetector::MINIMUM_BASELINE_POINTS)
                        {{ __('Not enough history in this range to set a baseline yet. Try a longer range.') }}
                    @else
                        {{ __('Nothing in this range moved beyond the rolling baseline.') }}
                    @endif
                </p>
            </div>
        </x-signal.ui.alert>
    @else
        <x-signal.ui.alert tone="info"><p class="text-sm">{{ __('Anomaly detection marks unusual shifts against a rolling baseline, with no threshold to tune. It comes with Monitoring Pro and above.') }}</p></x-signal.ui.alert>
    @endif

    <x-signal.ui.table :caption="__('Latest samples')">
        <x-slot:head><tr><th scope="col">{{ __('Time (UTC)') }}</th><th scope="col">{{ __('Value') }}</th><th scope="col">{{ __('State') }}</th><th scope="col">{{ __('Event') }}</th></tr></x-slot:head>
        @forelse (collect($chart['points'])->reverse()->take(50) as $point)
            <tr>
                <td class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($point['time'])->format('Y-m-d H:i:s.u') }}</td>
                <td class="font-mono">{{ $number($point['value']) }}</td>
                <td>{{ __(ucfirst(str_replace('_', ' ', $point['state']))) }}</td>
                <td><a href="{{ route('monitoring.events.show', [$project, $point['event_id']]) }}" class="font-bold text-primary hover:underline">{{ __('Open') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-8 text-center text-muted">{{ __('No samples in this range.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>

    <x-signal.ui.settings-section :title="__('Resource identity')" :description="__('Resource attributes and point labels, kept separately. Sensitive values are redacted.')">
        <x-signal.ui.code-block class="m-4 max-h-96 overflow-auto sm:m-6" :code="json_encode($series->descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)" />
    </x-signal.ui.settings-section>
</x-signal.layouts.project>
