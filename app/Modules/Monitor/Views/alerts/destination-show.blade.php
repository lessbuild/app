@extends('monitor::layouts.app')
@section('title', $destination->name)
@section('breadcrumb', 'Alert destinations')
@section('content')
<a href="{{ route('monitor.alert-destinations.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Alert destinations</a>
<x-monitor::ui.page-header :title="$destination->name" :description="$destination->type->label().' · '.$destination->targetLabel().' · '.($destination->trashed() ? 'Archived' : ($destination->enabled ? 'Enabled' : 'Paused'))">
    @can('update', $destination)
        <x-slot:actions><x-monitor::ui.button :href="route('monitor.alert-destinations.edit', $destination)" variant="secondary">Edit destination</x-monitor::ui.button></x-slot:actions>
    @endcan
</x-monitor::ui.page-header>
@if($issuedSecret)
@if(parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https')<p class="ui-alert ui-alert-warning block p-4 text-xs leading-5 text-warning dark:text-warning">This preview uses HTTP. Configure HTTPS and rotate this key before using production integrations.</p>@endif
<section class="ui-alert border-primary/30 bg-primary-soft block space-y-3 p-5"><h2 class="font-bold">Save this signing key now</h2><p class="text-sm">It will not be shown again. Store it securely in your receiver configuration.</p><code class="block break-all rounded-control bg-surface p-3 text-sm dark:bg-surface">{{ $issuedSecret }}</code></section>
@endif
@if($destination->type === \App\Modules\Monitor\Data\Telemetry\AlertDestinationType::Email && ! $mailConfigured)<p class="ui-card shadow-none border-warning p-4 text-sm text-warning dark:border-warning dark:text-warning">Email transport is not configured. Set SMTP credentials and ALERT_MAILER=alert_smtp on the server before testing.</p>@endif
<section class="ui-panel space-y-3 p-5 text-sm">
    <h2 class="font-bold">Delivery contract</h2>
    <p class="leading-6 text-muted dark:text-muted">Accepted means the remote provider acknowledged the request—not that a person read it or an email reached an inbox. Attempts have bounded timeouts. Temporary failures retry up to five attempts where safe; ambiguous email, Slack and Discord outcomes require review before retrying.</p>
    @if($destination->type === \App\Modules\Monitor\Data\Telemetry\AlertDestinationType::Webhook)
    <p class="leading-6 text-muted dark:text-muted">Webhook delivery is at least once: deduplicate using the stable <code>X-Beacon-Delivery</code> header or JSON <code>id</code>. Verify <code>X-Beacon-Signature</code> as <code>v1=HMAC-SHA256(key, timestamp + "." + raw request body)</code>, comparing in constant time. Use <code>X-Beacon-Timestamp</code> for a short replay window (for example, five minutes). Acknowledge with HTTP 2xx; redirects are not followed.</p>
    @elseif($destination->type === \App\Modules\Monitor\Data\Telemetry\AlertDestinationType::PagerDuty)
    <p class="leading-6 text-muted dark:text-muted">PagerDuty receives Events API v2 trigger and resolve events with a stable incident deduplication key. {{ config('app.name') }} retries provider failures and never displays the encrypted routing key.</p>
    @elseif($destination->type === \App\Modules\Monitor\Data\Telemetry\AlertDestinationType::Teams)
    <p class="leading-6 text-muted dark:text-muted">Teams receives a MessageCard payload through its HTTPS incoming webhook. A successful 2xx response is accepted; redirects are not followed.</p>
    @elseif($destination->type === \App\Modules\Monitor\Data\Telemetry\AlertDestinationType::Discord)
    <p class="leading-6 text-muted dark:text-muted">Discord receives a rich embed with mentions disabled. Monitor waits for Discord to confirm the posted message; ambiguous outcomes are marked uncertain rather than retried automatically, so a notification is not silently duplicated. The webhook URL is stored encrypted and never shown again.</p>
    @endif
    @can('update', $destination)
    <div class="flex flex-wrap gap-3">
        @if($destination->enabled)<form method="POST" action="{{ route('monitor.alert-destinations.test', $destination) }}">@csrf<input type="hidden" name="version" value="{{ $destination->state_version }}"><x-monitor::ui.button variant="secondary">Send test notification</x-monitor::ui.button></form>@endif
        @if($destination->type === \App\Modules\Monitor\Data\Telemetry\AlertDestinationType::Webhook)<form method="POST" action="{{ route('monitor.alert-destinations.rotate', $destination) }}" data-confirm="Rotate the signing key? Your receiver must be updated. In-flight requests may use the previous key.">@csrf<input type="hidden" name="version" value="{{ $destination->state_version }}"><x-monitor::ui.button variant="secondary">Rotate signing key</x-monitor::ui.button></form>@endif
    </div>
    @endcan
</section>
<section class="space-y-4">
    <h2 class="text-lg font-bold">Delivery history</h2>
    <div class="ui-card overflow-x-auto"><x-monitor::ui.table caption="Notification delivery history" :framed="false">
        <x-slot:head><tr><th scope="col">Event</th><th scope="col">Outcome</th><th scope="col">Attempts</th><th scope="col">Queued (UTC)</th></tr></x-slot:head>
        @forelse($deliveries as $delivery)<tr><td><a href="{{ route('monitor.alert-deliveries.show', $delivery) }}" class="font-semibold text-primary hover:underline dark:text-primary">{{ ucfirst($delivery->event) }} {{ $delivery->incident_id ? '#'.$delivery->incident_id : 'notification' }}</a></td><td>{{ $delivery->status->label() }}</td><td>{{ $delivery->attempt_count }}</td><td class="whitespace-nowrap">{{ $delivery->created_at->format('Y-m-d H:i:s') }}</td></tr>
        @empty<tr><td colspan="4" class="py-10 text-center text-muted dark:text-subtle">No deliveries yet. Route this destination on an alert rule or send a test.</td></tr>@endforelse
    </x-monitor::ui.table></div>
    {{ $deliveries->links() }}
</section>
@can('delete', $destination)<form method="POST" action="{{ route('monitor.alert-destinations.destroy', $destination) }}" data-confirm="Archive this destination? Queued deliveries will be cancelled; in-flight requests may finish." class="ui-panel shadow-none flex flex-col items-start gap-3 p-5">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $destination->state_version }}"><p class="text-xs text-muted dark:text-subtle">Archive to stop new deliveries and retain history. Requests already in flight may finish.</p><x-monitor::ui.button variant="danger">Archive destination</x-monitor::ui.button></form>@endcan
@endsection
