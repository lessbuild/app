@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Alert noise')" :description="__('The alerts that fired most in the last 30 days, how often they flapped, and how often anyone acted on them.')">
    @include('monitoring._alerts-tabs')

    @if ($rows === [])
        <x-signal.ui.empty-state icon="clock" :title="__('No alerts in the last 30 days')" :description="__('Quiet is good. Rules and monitors that open incidents will be ranked here.')" />
    @else
        <x-signal.ui.table :caption="__('Alert noise in the last 30 days')">
            <x-slot:head>
                <tr>
                    <th scope="col">{{ __('Alert') }}</th>
                    <th scope="col" class="text-right">{{ __('Fired') }}</th>
                    <th scope="col" class="text-right"><abbr title="{{ __('Recovered within :minutes minutes without anyone acknowledging it', ['minutes' => \App\Queries\Monitoring\AlertNoiseQuery::FLAP_MINUTES]) }}">{{ __('Flapped') }}</abbr></th>
                    <th scope="col" class="text-right">{{ __('Nobody acknowledged') }}</th>
                    <th scope="col" class="text-right">{{ __('Notifications') }}</th>
                    <th scope="col" class="text-right">{{ __('Median time open') }}</th>
                    <th scope="col">{{ __('Suggestion') }}</th>
                </tr>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr>
                    <td>
                        <a class="font-bold text-primary hover:underline" href="{{ $row['kind'] === 'rule' ? route('monitoring.rules.show', [$project, $row['id']]) : route('monitoring.monitors.show', [$project, $row['id']]) }}">{{ $row['name'] }}</a>
                        <span class="block text-xs text-muted">{{ $row['kind'] === 'rule' ? __('Alert rule') : __('Monitor') }}</span>
                    </td>
                    <td class="text-right tabular-nums">{{ number_format($row['fired']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($row['flapped']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($row['unacknowledged']) }} <span class="text-muted">({{ $row['unacknowledged_share'] }}%)</span></td>
                    <td class="text-right tabular-nums">{{ number_format($row['notifications']) }}</td>
                    <td class="text-right tabular-nums">{{ $row['median_minutes'] === null ? '—' : \Carbon\CarbonInterval::minutes($row['median_minutes'])->cascade()->forHumans(['short' => true, 'parts' => 2]) }}</td>
                    <td class="text-sm">{{ $row['suggestion'] ?? '—' }}</td>
                </tr>
            @endforeach
        </x-signal.ui.table>
    @endif
</x-signal.layouts.project>
