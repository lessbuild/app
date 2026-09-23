@extends('monitor::layouts.app')
@section('title', 'Alert destinations')
@section('breadcrumb', 'Alert destinations')
@section('content')
<x-monitor::ui.page-header title="Alert destinations" description="Choose where your team hears about incidents.">
    <x-slot:actions><x-monitor::ui.button :href="route('monitor.alert-destinations.create')">Add destination</x-monitor::ui.button></x-slot:actions>
</x-monitor::ui.page-header>
@unless($mailConfigured)<p class="ui-alert ui-alert-warning block p-4 text-xs leading-5 text-warning dark:text-warning">Email transport is not configured. Configure your server’s SMTP credentials and set ALERT_MAILER=alert_smtp. Log and array mailers never count as delivery. Slack, Teams, PagerDuty, Discord and HTTPS webhooks can be configured independently.</p>@endunless
<form method="GET" action="{{ route('monitor.alert-destinations.index') }}" class="flex flex-wrap items-end gap-3"><x-monitor::ui.select name="state" label="Destination state" :value="$state" :options="['all' => 'Current destinations', 'enabled' => 'Enabled', 'paused' => 'Paused', 'archived' => 'Archived']" /><x-monitor::ui.button variant="secondary">Filter</x-monitor::ui.button></form>
<div class="grid gap-4 lg:grid-cols-2">
    @forelse($destinations as $destination)
    <a href="{{ route('monitor.alert-destinations.show', $destination) }}" class="ui-card space-y-3 p-5 transition hover:border-primary dark:hover:border-primary">
        <div class="flex items-start justify-between gap-3"><h2 class="break-words font-bold">{{ $destination->name }}</h2><span class="shrink-0 rounded-control bg-surface-muted px-2 py-1 text-xs dark:bg-surface-muted">{{ $destination->trashed() ? 'Archived' : ($destination->enabled ? 'Enabled' : 'Paused') }}</span></div>
        <p class="break-words text-sm text-muted dark:text-subtle">{{ $destination->type->label() }} · {{ $destination->targetLabel() }}</p>
        <p class="text-xs text-muted dark:text-subtle">{{ $destination->alert_rules_count }} current rule routes · {{ $destination->monitors_count }} monitor routes · View delivery history →</p>
    </a>
    @empty
    <x-monitor::ui.empty-state icon="bell" class="lg:col-span-2" title="No destinations in this view." description="Add email, Slack, Teams, PagerDuty, Discord or a signed webhook, then select it on an alert rule." />
    @endforelse
</div>
{{ $destinations->links() }}
@endsection
