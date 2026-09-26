@extends('monitor::layouts.app')

@section('title', $objective ? 'Edit SLO' : 'Create SLO')
@section('breadcrumb', 'SLOs')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <a href="{{ $objective ? route('monitor.objectives.show', $objective) : route('monitor.objectives.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← SLOs</a>
        <x-monitor::ui.page-header :title="$objective ? 'Edit service objective' : 'Create service objective'" description="Measure one environment with a clear target. Keep the scope narrow enough that the budget tells an actionable story." />
        @if($environmentOptions === [])
            <x-signal.ui.card as="p" class="shadow-none p-6 text-sm">Create an application and environment before adding an objective. <a href="{{ route('monitor.applications.index') }}" class="font-semibold text-primary dark:text-primary">Manage applications →</a></x-signal.ui.card>
        @else
            <x-signal.ui.panel as="form" method="POST" action="{{ $objective ? route('monitor.objectives.update', $objective) : route('monitor.objectives.store') }}" class="space-y-5 p-6">
                @csrf
                @if($objective) @method('PATCH') @endif
                <x-monitor::ui.input name="name" label="Objective name (no secrets)" :value="$objective?->name" maxlength="120" required />
                <x-monitor::ui.select name="environment_id" label="Environment" :value="$objective?->environment_id" :options="$objective ? [$objective->environment_id => $environmentOptions[$objective->environment_id]] : $environmentOptions" required />
                <x-monitor::ui.select name="indicator" label="Indicator" :value="old('indicator', $objective?->indicator ?? 'availability')" :options="['availability' => 'Availability · successful HTTP status', 'latency' => 'Latency · duration under threshold']" required />
                <div class="grid gap-5 sm:grid-cols-2"><x-monitor::ui.input name="target" label="Target percentage" type="number" step="0.001" min="0.001" max="99.999" :value="$objective?->target ?? 99.9" required /><x-monitor::ui.select name="window_days" label="Rolling window" :value="$objective?->window_days ?? 30" :options="[7 => '7 days', 30 => '30 days']" required /></div>
                <div class="grid gap-5 sm:grid-cols-2"><x-monitor::ui.input name="service" label="Exact service label (optional)" :value="$objective?->service" maxlength="100" /><x-monitor::ui.input name="route" label="Exact route (optional)" :value="$objective?->route" maxlength="255" /></div>
                <x-signal.ui.card as="div" class="shadow-none p-4"><p class="text-xs font-bold text-ink dark:text-ink">Availability settings</p><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">A request is healthy when its reported status falls inside this inclusive range. Requests without a status are unknown.</p><div class="mt-4 grid gap-5 sm:grid-cols-2"><x-monitor::ui.input name="status_min" label="Healthy status minimum" type="number" min="100" max="599" :value="$objective?->status_min ?? 200" /><x-monitor::ui.input name="status_max" label="Healthy status maximum" type="number" min="100" max="599" :value="$objective?->status_max ?? 399" /></div></x-signal.ui.card>
                <x-signal.ui.card as="div" class="shadow-none p-4"><p class="text-xs font-bold text-ink dark:text-ink">Latency settings</p><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Only requests with a non-negative duration are measured. The threshold is inclusive and is ignored for availability objectives.</p><div class="mt-4 max-w-sm"><x-monitor::ui.input name="latency_threshold_ms" label="Healthy duration threshold (ms)" type="number" step="0.001" min="0.001" max="600000" :value="$objective?->latency_threshold_ms" /></div></x-signal.ui.card>
                <x-monitor::ui.select name="enabled" label="Evaluation" :value="$objective ? (int) $objective->enabled : 1" :options="[1 => 'Enabled', 0 => 'Paused']" required />
                <p class="text-xs leading-5 text-muted dark:text-subtle">The error budget is the allowed percentage below the target. Unknown records are shown separately, not silently counted as good. This is an observed telemetry SLO, not an uptime guarantee or a substitute for external checks.</p>
                <x-monitor::ui.button>{{ $objective ? 'Save objective' : 'Create objective' }}</x-monitor::ui.button>
            </x-signal.ui.panel>
        @endif
    </div>
@endsection
