@extends('monitor::layouts.app')
@section('title', 'Incidents')
@section('breadcrumb', 'Incidents')
@section('content')
<x-monitor::ui.page-header eyebrow="Reliability" title="Incident inbox" description="Threshold breaches, ownership acknowledgements and recovery history.">
    <x-slot:actions><x-monitor::ui.button :href="route('monitor.alerts.index')" variant="secondary">Manage alert rules</x-monitor::ui.button></x-slot:actions>
</x-monitor::ui.page-header>
<div class="grid gap-4 sm:grid-cols-3">
    @foreach(['open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Closed'] as $key => $label)
        <x-monitor::ui.stat-card :label="$label.' · workspace total'" :value="number_format($totals[$key] ?? 0)" />
    @endforeach
</div>
<form method="GET" action="{{ route('monitor.incidents.index') }}" class="flex flex-wrap items-end gap-3">
    <x-monitor::ui.select name="status" label="Status" :value="$status" :options="['active' => 'Active incidents', 'all' => 'All incidents', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Closed']" />
    @if($selectedRule)<input type="hidden" name="rule" value="{{ $selectedRule->id }}"><p class="py-2 text-xs">Rule: {{ $selectedRule->name }}</p>@endif
    <x-monitor::ui.button variant="secondary">Filter</x-monitor::ui.button>
</form>
<p class="text-xs text-muted dark:text-subtle">Acknowledgement does not indicate recovery. Closed incidents include configuration changes and archived rules; see each incident’s closure reason.</p>
<x-monitor::ui.incident-list :incidents="$incidents" />
@endsection
