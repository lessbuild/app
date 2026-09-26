<x-signal.ui.panel as="section" id="alert-delivery-history" class="ui-panel mt-6 scroll-mt-24 p-5 sm:p-6" aria-labelledby="alert-delivery-history-title">
    <p class="ui-eyebrow">{{ __('Outbound alerts') }}</p>
    <h2 id="alert-delivery-history-title" class="mt-1 text-xl font-extrabold text-ink">{{ __('Recent alert delivery history') }}</h2>
    <p class="mt-1 text-sm text-muted">{{ __('This history covers outbound webhook and email alerts. Incoming repository webhooks have a separate delivery history on each repository.') }}</p>

    <x-signal.ui.panel as="details" id="alert-delivery-history-list" class="ui-panel mt-5 bg-surface-muted p-4" :open="request()->query('section') === 'alert-delivery-history'">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-control font-bold text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
            <span>{{ __('Show recent deliveries') }}</span>
            <span class="flex items-center gap-2">
                @if ($alertDeliveries->isNotEmpty())
                    <x-signal.ui.badge>{{ $alertDeliveries->count() }}</x-signal.ui.badge>
                @endif
                <span class="text-muted" aria-hidden="true">⌄</span>
            </span>
        </summary>
        <div class="mt-4 space-y-3">
            @forelse ($alertDeliveries as $delivery)
                @php
                    $deliveryCanRetry = \Illuminate\Support\Facades\Gate::forUser(request()->user())->allows('retry', $delivery)
                        && $delivery->status === \App\Modules\Deployer\Models\AlertOutboundDelivery::STATUS_FAILED
                        && $delivery->manual_retry_count < \App\Modules\Deployer\Models\AlertOutboundDelivery::MAX_MANUAL_RETRIES
                        && $delivery->retry_available_until->isFuture()
                        && $delivery->destination?->is_active
                        && in_array($delivery->event, $delivery->destination->events ?? [], true);
                    $deliveryTone = match ($delivery->status) {
                        \App\Modules\Deployer\Models\AlertOutboundDelivery::STATUS_DELIVERED => 'success',
                        \App\Modules\Deployer\Models\AlertOutboundDelivery::STATUS_FAILED,
                        \App\Modules\Deployer\Models\AlertOutboundDelivery::STATUS_UNCERTAIN => 'danger',
                        default => 'neutral',
                    };
                @endphp
                <article class="rounded-card border border-line bg-surface p-4" data-alert-delivery>
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-bold text-ink">{{ ucfirst($delivery->event) }} · {{ $delivery->destination?->name ?? __('Deleted destination') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ ucfirst($delivery->destination_type) }} · {{ $delivery->created_at->format('Y-m-d H:i:s T') }} · {{ trans_choice(':count attempt|:count attempts', $delivery->attempt_count, ['count' => $delivery->attempt_count]) }}</p>
                        </div>
                        <x-signal.ui.badge :tone="$deliveryTone">{{ str($delivery->status)->headline() }}</x-signal.ui.badge>
                    </div>
                    <dl class="mt-3 grid gap-3 text-xs sm:grid-cols-3">
                        <div><dt class="font-bold uppercase tracking-wide text-muted">{{ __('Latest HTTP status') }}</dt><dd class="mt-1 text-ink">{{ $delivery->http_status ?? __('No HTTP response') }}</dd></div>
                        <div><dt class="font-bold uppercase tracking-wide text-muted">{{ __('Safe error code') }}</dt><dd class="mt-1 text-ink">{{ $delivery->error_code ?? __('None') }}</dd></div>
                        <div><dt class="font-bold uppercase tracking-wide text-muted">{{ __('Manual retries') }}</dt><dd class="mt-1 text-ink">{{ $delivery->manual_retry_count }} / {{ \App\Modules\Deployer\Models\AlertOutboundDelivery::MAX_MANUAL_RETRIES }}</dd></div>
                    </dl>
                    @if ($delivery->attempts->isNotEmpty())
                        <ol class="mt-3 space-y-1 border-t border-line pt-3 text-xs text-muted" aria-label="{{ __('Recent delivery attempts') }}">
                            @foreach ($delivery->attempts as $attempt)
                                <li>{{ __('Attempt :number', ['number' => $attempt->number]) }} · {{ str($attempt->status)->headline() }} · {{ $attempt->error_code ?? __('No error code') }} · {{ $attempt->http_status ?? __('No HTTP status') }}</li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($deliveryCanRetry)
                        <form method="POST" action="{{ route('observability.alert-deliveries.retry', $delivery) }}" class="mt-4 flex flex-wrap items-center gap-3 border-t border-line pt-3">
                            @csrf
                            <x-signal.ui.checkbox
                                id="confirm-alert-delivery-{{ $delivery->id }}"
                                name="confirm"
                                value="1"
                                :required="true"
                                :restore="false"
                                :show-errors="false"
                                container-class="text-xs text-muted"
                            >{{ __('I reviewed this failed send and accept that the remote service may have received it.') }}</x-signal.ui.checkbox>
                            <x-signal.ui.button type="submit" variant="secondary">{{ __('Retry once') }}</x-signal.ui.button>
                        </form>
                    @endif
                </article>
            @empty
                <x-signal.ui.empty-state :title="__('No outbound alert deliveries yet')" :description="__('Delivery attempts will appear here after an alert event is sent to a configured destination.')" icon="bell" />
            @endforelse
        </div>
        <p class="mt-4 text-xs text-muted">{{ __('Showing the latest 25 delivery records. Event bodies, destination addresses, signing secrets, and provider response bodies are not shown.') }}</p>
    </x-signal.ui.panel>
</x-signal.ui.panel>
