@php($secret = session('webhook_secret'))

<x-signal.layouts.account :account="$account" :title="__('Webhooks')" :description="__('Send :account’s events (deploys, incidents, servers, backups, security findings, billing and more) to your own automation as signed JSON.', ['account' => $account->name])">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if (is_string($secret))
        <x-signal.ui.panel as="section" class="space-y-4 border-warning p-6" aria-labelledby="webhook-secret-heading">
            <div>
                <p class="ui-eyebrow">{{ __('Copy it now') }}</p>
                <h2 id="webhook-secret-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Signing secret') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('This is the only time it is shown. Use it to check the X-BuildPusher-Signature header.') }}</p>
            </div>
            <x-signal.ui.code-block :code="$secret" class="break-all whitespace-pre-wrap" />
        </x-signal.ui.panel>
    @endif

    <x-slot:actions>
        <x-signal.ui.button :href="route('account.webhooks', ['dialog' => 'add-webhook'])" variant="primary" data-modal-trigger="add-webhook">{{ __('Add an endpoint') }}</x-signal.ui.button>
    </x-slot:actions>
    <x-signal.overlays.form-modal id="add-webhook" :title="__('Add an endpoint')" :action="route('account.webhooks.store')" :submit="__('Add endpoint')">
        <x-signal.ui.input-field id="webhook-url" name="url" type="url" :label="__('Address')" :description="__('A public HTTPS address. It gets a POST for each event.')" maxlength="2048" placeholder="https://example.com/hooks/buildpusher" required />
        <x-signal.ui.input-field id="webhook-description" name="description" :label="__('Description')" maxlength="200" :placeholder="__('Deploy announcements')" />
        @include('account._webhook-events', ['prefix' => 'new-webhook', 'selected' => (array) old('events', ['*'])])
    </x-signal.overlays.form-modal>

    @forelse ($endpoints as $endpoint)
        <x-signal.ui.settings-section :id="'webhook-'.$endpoint->id" :title="$endpoint->description ?? $endpoint->url" :description="$endpoint->description ? $endpoint->url : null">
            <div class="grid gap-4 p-4 sm:p-6">
                <div class="flex flex-wrap items-center gap-3">
                    <x-signal.ui.badge :tone="$endpoint->enabled ? 'success' : 'warning'">{{ $endpoint->enabled ? __('On') : __('Paused') }}</x-signal.ui.badge>
                    <span class="text-sm text-muted">{{ in_array('*', $endpoint->events, true) ? __('All events') : trans_choice(':count event|:count events', count($endpoint->events)) }}</span>
                    @if ($endpoint->last_delivered_at)<span class="text-sm text-muted">· {{ __('last delivered :time', ['time' => $endpoint->last_delivered_at->diffForHumans()]) }}</span>@endif
                    <form method="POST" action="{{ route('account.webhooks.send', $endpoint->id) }}" class="ml-auto">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Send a test') }}</x-signal.ui.button></form>
                </div>
                @if ($endpoint->last_error)
                    <x-signal.ui.alert :tone="$endpoint->enabled ? 'warning' : 'danger'">{{ $endpoint->enabled ? __('Last failure: :error', ['error' => $endpoint->last_error]) : __('Paused after :count failed deliveries in a row (last: :error). Fix the address, then turn it back on.', ['count' => \App\Models\WebhookEndpoint::MAX_FAILURES, 'error' => $endpoint->last_error]) }}</x-signal.ui.alert>
                @endif

                @php($recent = $deliveries->get($endpoint->id, collect()))
                @if ($recent->isNotEmpty())
                    <x-signal.ui.table :caption="__('Recent deliveries')" :framed="false">
                        <x-slot:head><tr><th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('Result') }}</th><th scope="col">{{ __('Tries') }}</th><th scope="col">{{ __('When') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></x-slot:head>
                        @foreach ($recent as $delivery)
                            <tr>
                                <td class="font-mono text-xs">{{ $delivery->event }}</td>
                                <td>
                                    <x-signal.ui.badge :tone="match ($delivery->status) { 'delivered' => 'success', 'failed' => 'danger', default => 'neutral' }">{{ match ($delivery->status) { 'delivered' => __('Delivered'), 'failed' => __('Failed'), default => __('Pending') } }}</x-signal.ui.badge>
                                    @if ($delivery->response_status)<span class="text-xs text-muted">HTTP {{ $delivery->response_status }}</span>@endif
                                    @if ($delivery->error && $delivery->status !== 'delivered')<p class="text-xs text-muted">{{ $delivery->error }}</p>@endif
                                </td>
                                <td class="tabular-nums">{{ $delivery->attempts }}</td>
                                <td class="text-sm text-muted">{{ $delivery->created_at?->diffForHumans() }}</td>
                                <td class="text-right">
                                    @if ($delivery->status !== 'pending')
                                        <form method="POST" action="{{ route('account.webhooks.send', $endpoint->id) }}">@csrf<input type="hidden" name="delivery" value="{{ $delivery->id }}"><x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Send again') }}</x-signal.ui.button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-signal.ui.table>
                @else
                    <p class="text-sm text-muted">{{ __('Nothing sent yet.') }}</p>
                @endif

                <x-signal.ui.disclosure :title="__('Edit')">
                    <form method="POST" action="{{ route('account.webhooks.update', $endpoint->id) }}" class="grid gap-4">
                        @csrf
                        @method('PUT')
                        <x-signal.ui.input-field :id="'webhook-url-'.$endpoint->id" name="url" type="url" :label="__('Address')" :value="$endpoint->url" maxlength="2048" :restore="false" required />
                        <x-signal.ui.input-field :id="'webhook-description-'.$endpoint->id" name="description" :label="__('Description')" :value="$endpoint->description" maxlength="200" :restore="false" />
                        @include('account._webhook-events', ['prefix' => 'webhook-'.$endpoint->id, 'selected' => $endpoint->events])
                        <x-signal.ui.checkbox :id="'webhook-enabled-'.$endpoint->id" name="enabled" :checked="$endpoint->enabled" :restore="false">{{ __('On') }}</x-signal.ui.checkbox>
                        <div class="flex flex-wrap gap-3">
                            <x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('account.webhooks.destroy', $endpoint->id) }}" class="mt-4">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove endpoint') }}</x-signal.ui.button></form>
                </x-signal.ui.disclosure>
            </div>
        </x-signal.ui.settings-section>
    @empty
        <x-signal.ui.empty-state icon="bell" :title="__('No endpoints yet')" :description="__('Add an endpoint to receive events as they happen, for chat bots, dashboards, ticketing or anything else you run.')" />
    @endforelse

    <x-signal.ui.settings-section id="verifying" :title="__('Checking the signature')" :description="__('Each request has X-BuildPusher-Event, X-BuildPusher-Delivery (the same ID if it’s sent again), X-BuildPusher-Timestamp and X-BuildPusher-Signature. Recompute the signature from the raw body and reject requests older than five minutes. Answer with any 2xx within 10 seconds; anything else is tried again up to six times over about three hours.')">
        <div class="p-4 sm:p-6">
            <x-signal.ui.code-block :code="\App\Support\Webhooks\WebhookExamples::PHP" />
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
