@extends('monitor::layouts.app')
@section('title', $destination ? 'Edit destination' : 'Add destination')
@section('breadcrumb', 'Alert destinations')
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ $destination ? route('monitor.alert-destinations.show', $destination) : route('monitor.alert-destinations.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Alert destinations</a>
    <x-monitor::ui.page-header :title="$destination ? 'Edit destination' : 'Add destination'" />
    @if(parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https')<x-signal.ui.alert as="p" tone="warning" class="block p-4 text-xs leading-5 text-warning dark:text-warning">This preview uses HTTP. Configure HTTPS before entering production webhook URLs or other secrets.</x-signal.ui.alert>@endif
    <x-signal.ui.panel as="form" method="POST" action="{{ $destination ? route('monitor.alert-destinations.update', $destination) : route('monitor.alert-destinations.store') }}" class="space-y-5 p-6">
        @csrf
        @if($destination) @method('PATCH') <x-signal.ui.input type="hidden" name="version" value="{{ $destination->state_version }}" :restore="false" /> @endif
        <x-monitor::ui.input name="name" label="Destination name (no secrets)" :value="$destination?->name" maxlength="120" required />
        <x-monitor::ui.select name="type" label="Channel" :value="$destination?->type->value ?? 'webhook'" :options="$destination ? [$destination->type->value => $destination->type->label()] : $types" required />
        <x-monitor::ui.select name="recipient_user_id" label="Email recipient — email destinations only" :value="$destination?->recipient_user_id" :options="$recipients" placeholder="Choose a verified workspace member" />
        <x-monitor::ui.input name="endpoint_url" label="Secret endpoint URL — signed webhooks, Slack, Teams or Discord" type="password" autocomplete="new-password" maxlength="2048" />
        <p class="-mt-3 text-xs leading-5 text-muted dark:text-subtle">For Discord, paste the full incoming webhook URL (<code>https://discord.com/api/webhooks/{id}/{token}</code>). The URL contains a secret and is encrypted at rest.</p>
        <x-monitor::ui.input name="signing_secret" label="PagerDuty integration routing key — PagerDuty only" type="password" autocomplete="new-password" maxlength="256" />
        <p class="text-xs leading-5 text-muted dark:text-subtle">HTTPS on port 443 only, using a provider allowlist. Private and reserved IP addresses are blocked at delivery time. PagerDuty uses its Events API v2 endpoint and keeps the routing key encrypted. URLs and keys are never displayed again. @if($destination)Leave secret fields blank to keep the current values.@endif</p>
        <x-monitor::ui.select name="enabled" label="Delivery" :value="$destination ? (int) $destination->enabled : 1" :options="[1 => 'Enabled', 0 => 'Paused']" required />
        <p class="rounded-control bg-surface-muted p-4 text-xs leading-5 text-muted dark:bg-surface-muted dark:text-muted">Creating a destination sends nothing automatically. Select it on an alert rule or send an explicit test. Changing the target, pausing or rotating its key invalidates queued deliveries; a request already in flight may still finish.</p>
        <x-monitor::ui.button>{{ $destination ? 'Save destination' : 'Create destination' }}</x-monitor::ui.button>
    </x-signal.ui.panel>
</div>
@endsection
