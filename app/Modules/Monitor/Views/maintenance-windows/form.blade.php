@extends('monitor::layouts.app')
@section('title', $window->exists ? 'Edit maintenance window' : 'Schedule maintenance')
@section('breadcrumb', 'Maintenance')
@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <x-monitor::ui.page-header eyebrow="Incident response" :title="$window->exists ? 'Adjust the quiet period.' : 'Schedule planned work.'" description="Use UTC. Monitoring continues, but new alert and uptime incidents will not notify destinations while the window is active." />
    <form method="POST" action="{{ $window->exists ? route('monitor.maintenance-windows.update', $window) : route('monitor.maintenance-windows.store') }}" class="space-y-6">
        @csrf
        @if($window->exists) @method('PATCH') @endif
        <x-signal.ui.panel as="section" class="space-y-5 p-6">
            <x-monitor::ui.input name="name" label="Window name" :value="$window->name" maxlength="120" placeholder="Production deploy" required />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-monitor::ui.input name="starts_at" label="Starts (UTC)" type="datetime-local" :value="$window->starts_at?->format('Y-m-d\\TH:i')" required />
                <x-monitor::ui.input name="ends_at" label="Ends (UTC)" type="datetime-local" :value="$window->ends_at?->format('Y-m-d\\TH:i')" required />
            </div>
            <x-monitor::ui.textarea name="reason" label="Reason (optional)" :value="$window->reason" maxlength="1000" />
        </x-signal.ui.panel>
        <div class="flex items-center justify-between gap-3">
            <x-monitor::ui.button :href="route('monitor.maintenance-windows.index')" variant="quiet">Cancel</x-monitor::ui.button>
            <x-monitor::ui.button>{{ $window->exists ? 'Save window' : 'Schedule window' }}</x-monitor::ui.button>
        </div>
    </form>
</div>
@endsection
