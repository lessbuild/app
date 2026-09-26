@extends('monitor::layouts.app')
@section('title', $dashboard->exists ? 'Edit dashboard' : 'Create dashboard')
@section('breadcrumb', 'Dashboards')
@section('content')
@php
    $defaultWidgets = $dashboard->exists ? $dashboard->widgets->pluck('type')->all() : array_keys($widgetTypes);
    $oldWidgets = old('widgets', session()->hasOldInput() ? [] : $defaultWidgets);
    $selectedWidgets = is_array($oldWidgets) ? $oldWidgets : [];
    $descriptions = [
        'telemetry' => 'Stored event volume, request duration, and error rate for the selected range.',
        'event_mix' => 'A compact breakdown of requests, queries, jobs, exceptions, logs, and metrics.',
        'incidents' => 'Active alert and uptime incidents that need a human response.',
        'monitors' => 'Current health for HTTP, DNS, TLS, heartbeat, and queue monitors.',
        'objectives' => 'Rolling SLO compliance and remaining error budgets.',
        'applications' => 'Application inventory and the latest recorded activity.',
    ];
@endphp
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ $dashboard->exists ? route('monitor.dashboards.show', $dashboard) : route('monitor.dashboards.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Dashboards</a>
    <x-monitor::ui.page-header eyebrow="Team observability" :title="$dashboard->exists ? 'Tune this team view.' : 'Create a team view.'" description="Choose the signals this dashboard should bring together. Data remains scoped to the current workspace." />
    @error('plan')<x-signal.ui.alert as="p" tone="warning" class="block p-4 text-sm text-warning dark:text-warning">{{ $message }}</x-signal.ui.alert>@enderror
    <form method="POST" action="{{ $dashboard->exists ? route('monitor.dashboards.update', $dashboard) : route('monitor.dashboards.store') }}" class="space-y-6">
        @csrf
        @if($dashboard->exists) @method('PATCH') @endif
        <x-signal.ui.panel as="section" class="space-y-5 p-6">
            <x-monitor::ui.input name="name" label="Dashboard name" :value="$dashboard->name" maxlength="120" placeholder="Production operations" required />
            <x-monitor::ui.textarea name="description" label="Description (optional)" :value="$dashboard->description" maxlength="1000" />
            <x-monitor::ui.select name="range" label="Telemetry time range" :value="$dashboard->range" :options="\App\Modules\Monitor\Models\Dashboard::RANGES" required />
        </x-signal.ui.panel>
        <x-signal.ui.card as="fieldset" class="space-y-4 p-6" aria-describedby="widgets-help{{ $errors->has('widgets') ? ' widgets-error' : '' }}">
            <legend class="px-2 font-bold">Widgets</legend>
            <p id="widgets-help" class="ui-help">Pick at least one. Widget order follows this list.</p>
            @error('widgets')<p id="widgets-error" class="ui-error">{{ $message }}</p>@enderror
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach($widgetTypes as $type => $label)
                    <x-monitor::ui.choice :id="'widget-'.$type" name="widgets[]" :value="$type" :checked="in_array($type, $selectedWidgets, true)" :label="$label" :description="$descriptions[$type]" card />
                @endforeach
            </div>
        </x-signal.ui.card>
        <div class="flex items-center justify-between gap-3"><x-monitor::ui.button :href="$dashboard->exists ? route('monitor.dashboards.show', $dashboard) : route('monitor.dashboards.index')" variant="quiet">Cancel</x-monitor::ui.button><x-monitor::ui.button>{{ $dashboard->exists ? 'Save dashboard' : 'Create dashboard' }}</x-monitor::ui.button></div>
    </form>
</div>
@endsection
