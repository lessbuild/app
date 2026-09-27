@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Events')" :description="__('Every request, query, job, log, exception and metric your environments sent.')">
    <form method="GET" action="{{ route('monitoring.events', $project) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="search">
        <x-signal.ui.input-field name="q" :label="__('Search')" :value="$filters['q'] ?? null" :restore="false" />
        <x-signal.ui.select-field name="environment" :label="__('Environment')">
            <option value="">{{ __('All') }}</option>
            @foreach ($overview->environments as $environment)
                <option value="{{ $environment->id }}" @selected(($filters['environment'] ?? null) === $environment->id)>{{ $environment->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="type" :label="__('Type')">
            <option value="">{{ __('All') }}</option>
            @foreach ($typeOptions as $value => $label)
                <option value="{{ $value }}" @selected(($filters['type'] ?? null) === $value)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="severity" :label="__('Severity')">
            <option value="">{{ __('All') }}</option>
            @foreach ($severityOptions as $value => $label)
                <option value="{{ $value }}" @selected(($filters['severity'] ?? null) === $value)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="range" :label="__('Time')">
            @foreach ($rangeOptions as $value => $label)
                @continue($value === 'custom')
                <option value="{{ $value }}" @selected($filters['range'] === $value)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="sort" :label="__('Order')">
            @foreach ($sortOptions as $value => $label)
                <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.input-field name="trace" :label="__('Trace ID')" :value="$filters['trace'] ?? null" :restore="false" />
        <div class="flex items-end gap-2">
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Filter') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('monitoring.dependencies', $project)" variant="quiet">{{ __('Service map') }}</x-signal.ui.button>
        </div>
    </form>

    <x-signal.ui.table :caption="__('Events')">
        <x-slot:head><tr><th scope="col">{{ __('When (UTC)') }}</th><th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Duration') }}</th><th scope="col">{{ __('Trace') }}</th></tr></x-slot:head>
        @forelse ($records as $record)
            @php($event = $record->event)
            <tr>
                <td class="whitespace-nowrap"><a class="text-primary hover:underline" href="{{ route('monitoring.events.show', [$project, $event->id]) }}">{{ $event->occurred_at->format('Y-m-d H:i:s') }}</a></td>
                <td class="min-w-48">
                    <span class="flex flex-wrap items-center gap-2"><x-signal.ui.badge :tone="$record->tone()">{{ __(ucfirst($event->type)) }}</x-signal.ui.badge><span class="break-all">{{ $record->name() }}</span></span>
                    <span class="text-xs text-muted">{{ $event->environment->name }}@if ($event->status_code) · {{ $event->status_code }}@endif</span>
                </td>
                <td>{{ $event->service ?? '—' }}</td>
                <td class="whitespace-nowrap">{{ $record->durationLabel() }}</td>
                <td>@if ($event->trace_id)<a class="text-primary hover:underline" href="{{ route('monitoring.traces.show', [$project, $event->trace_id]) }}">{{ __('View') }}</a>@else — @endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('No events match. Try a longer time range, or connect an app on the Setup page.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
    @include('telemetry._pager', ['paginator' => $events])
</x-signal.layouts.project>
