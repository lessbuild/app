@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Service map')" :description="__('Which services call which, built from trace spans.')">
    <form method="GET" action="{{ route('monitoring.dependencies', $project) }}" class="flex flex-wrap items-end gap-3">
        <x-signal.ui.select-field name="range" :label="__('Time')">
            @foreach ($rangeOptions as $value => $label)
                <option value="{{ $value }}" @selected($filters['range'] === $value)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="environment" :label="__('Environment')">
            <option value="">{{ __('All') }}</option>
            @foreach ($overview->environments as $environment)
                <option value="{{ $environment->id }}" @selected(($filters['environment'] ?? null) === $environment->id)>{{ $environment->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Show') }}</x-signal.ui.button>
    </form>

    @if ($map['truncated'])
        <x-signal.ui.alert tone="warning">{{ __('Only the first :count spans are included. Pick a shorter time range for a complete map.', ['count' => number_format(20000)]) }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.table :caption="__('Calls between services')">
        <x-slot:head><tr><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('To') }}</th><th scope="col">{{ __('Calls') }}</th><th scope="col">{{ __('Errors') }}</th><th scope="col">{{ __('Average') }}</th></tr></x-slot:head>
        @forelse ($map['edges'] as $edge)
            <tr>
                <td>{{ $edge['source'] }}</td>
                <td>{{ $edge['target'] }}</td>
                <td>{{ number_format($edge['calls']) }}</td>
                <td>{{ number_format($edge['error_rate'], 1) }}%</td>
                <td class="whitespace-nowrap">{{ $edge['average_duration'] !== null ? number_format($edge['average_duration'], 1).' ms' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('No calls between services in this range. Spans need a parent in another service to appear here.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>

    <x-signal.ui.table :caption="__('Services')">
        <x-slot:head><tr><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Spans') }}</th><th scope="col">{{ __('Errors') }}</th><th scope="col">{{ __('Average') }}</th></tr></x-slot:head>
        @forelse ($map['services'] as $service)
            <tr>
                <td>{{ $service['name'] }}</td>
                <td>{{ number_format($service['span_count']) }}</td>
                <td>{{ number_format($service['error_rate'], 1) }}%</td>
                <td class="whitespace-nowrap">{{ $service['average_duration'] !== null ? number_format($service['average_duration'], 1).' ms' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-10 text-center text-muted">{{ __('No spans in this range.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
</x-signal.layouts.project>
