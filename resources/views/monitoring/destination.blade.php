@php($project = $overview->project)
@php($types = \App\Enums\AlertDestinationType::class)

<x-signal.layouts.project :overview="$overview" :title="$destination->name" :description="$destination->type->label().' · '.$destination->targetLabel()">
    @if ($destination->trashed())
        <x-signal.ui.alert tone="info">{{ __('This destination is archived. It no longer receives alerts; its delivery history stays here.') }}</x-signal.ui.alert>
    @endif

    @if ($issuedKey)
        <x-signal.ui.alert tone="success" role="status">
            <p class="font-bold">{{ __('Copy the signing key now. It won’t be shown again.') }}</p>
            <x-signal.ui.code-block :code="$issuedKey" class="mt-2 break-all whitespace-pre-wrap" />
        </x-signal.ui.alert>
    @endif

    @if ($destination->type === $types::Webhook)
        <x-signal.ui.card class="grid gap-2 p-5 text-sm text-muted">
            <p>{{ __('Deliveries can arrive more than once: use the X-Beacon-Delivery header (or the JSON id) to skip duplicates.') }}</p>
            <p>{{ __('Check X-Beacon-Signature, which is v1= followed by HMAC-SHA256(key, timestamp + "." + raw body), and reject old X-Beacon-Timestamp values. Reply with any 2xx status; redirects aren’t followed.') }}</p>
        </x-signal.ui.card>
    @endif

    @if ($canManage)
        <div class="flex flex-wrap gap-2">
            @if ($destination->enabled)
                <form method="POST" action="{{ route('monitoring.destinations.test', [$project, $destination->id]) }}">
                    @csrf
                    <input type="hidden" name="version" value="{{ $destination->state_version }}">
                    <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Send a test') }}</x-signal.ui.button>
                </form>
            @endif
            @if ($destination->type === $types::Webhook)
                <form method="POST" action="{{ route('monitoring.destinations.rotate', [$project, $destination->id]) }}">
                    @csrf
                    <input type="hidden" name="version" value="{{ $destination->state_version }}">
                    <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Replace signing key') }}</x-signal.ui.button>
                </form>
            @endif
        </div>

        <x-signal.ui.settings-section :title="__('Settings')" :description="__('Changing the address or recipient cancels deliveries still waiting to be sent.')">
            <form method="POST" action="{{ route('monitoring.destinations.update', [$project, $destination->id]) }}" class="grid gap-5 p-4 sm:p-6">
                @csrf
                @method('PUT')
                @include('monitoring._destination-fields', ['destination' => $destination])
                <div><x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>
    @endif

    <x-signal.ui.table :caption="__('Deliveries')">
        <x-slot:head><tr><th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('Outcome') }}</th><th scope="col">{{ __('Attempts') }}</th><th scope="col">{{ __('Queued (UTC)') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></x-slot:head>
        @forelse ($deliveries as $delivery)
            <tr>
                <td>{{ __(ucfirst($delivery->event)) }}@if ($delivery->incident_id) · <a class="text-primary hover:underline" href="{{ route('monitoring.incidents.show', [$project, $delivery->incident_id]) }}">#{{ $delivery->incident_id }}</a>@endif</td>
                <td>{{ __($delivery->status->label()) }}@if ($delivery->last_error_code) <span class="text-xs text-muted">({{ $delivery->last_error_code }})</span>@endif</td>
                <td>{{ $delivery->attempt_count }}</td>
                <td class="whitespace-nowrap">{{ $delivery->created_at?->format('Y-m-d H:i:s') }}</td>
                <td class="text-right">
                    @if ($canManage && $delivery->status->retryable())
                        <form method="POST" action="{{ route('monitoring.deliveries.retry', [$project, $delivery->id]) }}">
                            @csrf
                            <input type="hidden" name="generation" value="{{ $delivery->generation }}">
                            <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Retry') }}</x-signal.ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-8 text-center text-muted">{{ __('No deliveries yet. Send a test, or choose this destination on a monitor.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Archive this destination')" :description="__('It stops receiving alerts and deliveries still waiting are cancelled. Its history is kept.')">
            <div class="p-4 sm:p-6">
                <x-signal.ui.button variant="danger" data-modal-trigger="archive-destination">{{ __('Archive :destination', ['destination' => $destination->name]) }}</x-signal.ui.button>
                <x-signal.overlays.delete-confirmation id="archive-destination" :route="route('monitoring.destinations.archive', [$project, $destination->id])" :title="__('Archive :destination?', ['destination' => $destination->name])" :description="__('Monitors stop sending alerts here.')" :submit-label="__('Archive')">
                    <input type="hidden" name="version" value="{{ $destination->state_version }}">
                </x-signal.overlays.delete-confirmation>
            </div>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
