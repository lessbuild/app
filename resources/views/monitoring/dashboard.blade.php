@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$dashboard->name" :description="($dashboard->description ? $dashboard->description.' · ' : '').$rangeLabel">
    <div class="flex flex-wrap items-center justify-between gap-3">
        @include('telemetry._metrics-tabs')
        @if ($canManage)
            <x-signal.ui.button :href="route('monitoring.dashboards.edit', [$project, $dashboard->id])" variant="secondary">{{ __('Edit') }}</x-signal.ui.button>
        @endif
    </div>
    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($widgets as $widget)
            <x-signal.ui.card @class(['min-w-0 p-5 sm:p-6', 'xl:col-span-2' => $widget['type'] === 'telemetry'])>
                <h2 class="text-lg font-extrabold text-ink">{{ $widget['label'] }}</h2>
                @include('monitoring.widgets.'.$widget['type'], $widget['data'])
            </x-signal.ui.card>
        @endforeach
    </div>
</x-signal.layouts.project>
