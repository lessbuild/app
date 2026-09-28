@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Metrics')" :description="__('Numeric series from any stack. Each series keeps its host, container, database or custom labels, so unrelated resources are never averaged together.')">
    @include('telemetry._metrics-tabs')

    <form method="GET" action="{{ route('monitoring.metrics', $project) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="search">
        <x-signal.ui.input-field name="q" :label="__('Metric, unit or resource')" :value="$filters['q'] ?? null" :restore="false" placeholder="system.memory.usage" />
        <x-signal.ui.select-field name="environment" :label="__('Environment')">
            <option value="">{{ __('All') }}</option>
            @foreach ($overview->environments as $environment)
                <option value="{{ $environment->id }}" @selected(($filters['environment'] ?? null) === $environment->id)>{{ $environment->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="kind" :label="__('Type')">
            <option value="">{{ __('All') }}</option>
            @foreach ($kindOptions as $value => $label)
                <option value="{{ $value }}" @selected(($filters['kind'] ?? null) === $value)>{{ __($label) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <div class="flex items-end gap-2">
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Filter') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('monitoring.metrics', $project)" variant="quiet">{{ __('Reset') }}</x-signal.ui.button>
        </div>
    </form>
    @error('saved_view_name')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    <x-signal.ui.saved-views page="monitoring.metrics" :parameters="['project' => $project->id]" />

    <x-signal.ui.table :caption="__('Metric series')">
        <x-slot:head><tr><th scope="col">{{ __('Metric') }}</th><th scope="col">{{ __('Resource') }}</th><th scope="col">{{ __('Type / unit') }}</th><th scope="col">{{ __('Last received (UTC)') }}</th>@if ($canManage)<th scope="col"><span class="sr-only">{{ __('Alert') }}</span></th>@endif</tr></x-slot:head>
        @forelse ($series as $item)
            <tr>
                <td class="min-w-48">
                    <a href="{{ route('monitoring.metrics.show', [$project, $item->id]) }}" class="break-all font-bold text-primary hover:underline">{{ $item->name }}</a>
                    <span class="block text-xs text-muted">{{ $item->environment->name }}</span>
                </td>
                <td class="max-w-xs break-all font-mono text-xs">{{ $item->resource_label }}</td>
                <td class="whitespace-nowrap">{{ $item->kind }} <span class="text-muted">{{ $item->unit ?: __('unitless') }}</span></td>
                <td class="whitespace-nowrap text-muted">{{ $item->last_received_at->format('Y-m-d H:i:s') }}</td>
                @if ($canManage)
                    <td class="whitespace-nowrap"><a href="{{ route('monitoring.rules.create', ['project' => $project, 'metric' => 'numeric_metric', 'series' => $item->id]) }}" class="font-bold text-primary hover:underline">{{ __('Create alert') }}</a></td>
                @endif
            </tr>
        @empty
            <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('No metric series yet. Send OTLP metrics (a collector setup is below) or JSON events with a numeric value.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
    @include('telemetry._pager', ['paginator' => $series])

    <section class="grid gap-4" aria-labelledby="collector-setups">
        <div>
            <h2 id="collector-setups" class="text-xl font-extrabold text-ink">{{ __('Collector setups') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('OpenTelemetry Collector configurations that send metrics to this project as OTLP JSON. Set BEACON_INGEST_TOKEN to an ingest key from the Setup page, and the other variables as secrets.') }}</p>
        </div>
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line">
                @foreach ($profiles as $key => $profile)
                    <li>
                        <details class="group px-5 py-4">
                            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3">
                                <span class="font-bold text-ink">{{ $profile['label'] }}</span>
                                <x-signal.ui.badge tone="neutral">{{ $profile['stability'] }}</x-signal.ui.badge>
                            </summary>
                            <p class="mt-3 text-sm leading-6 text-muted">{{ $profile['requirements'] }}</p>
                            <x-signal.ui.code-block class="mt-3 max-h-96 overflow-auto" :code="$profile['yaml']" />
                        </details>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    </section>
</x-signal.layouts.project>
