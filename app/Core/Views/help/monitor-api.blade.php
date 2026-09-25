<x-signal.layouts.core
    :title="__('Monitor API reference') . ' · Buildpusher'"
    :description="__('Monitor ingestion, deployment, heartbeat, and queue APIs.')"
    :canonical="route('core.help.monitor.api')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl px-5 py-12 sm:px-8 sm:py-16">
        <x-signal.ui.page-header
            eyebrow="{{ __('Buildpusher Monitor') }}"
            :title="__('One contract for every stack.')"
            :description="__('Use the versioned Monitor API for native clients, collectors, and OpenTelemetry exporters. The public contract contains no workspace secrets.')"
        >
            <x-slot:actions>
                <x-signal.ui.button :href="route('core.help')" variant="secondary">{{ __('All help and guides') }}</x-signal.ui.button>
                <x-signal.ui.button :href="$reference->openApiUrl" variant="primary" target="_blank" rel="noreferrer">{{ __('OpenAPI JSON') }}</x-signal.ui.button>
            </x-slot:actions>
        </x-signal.ui.page-header>

        <section class="mt-7 grid gap-4 lg:grid-cols-3" aria-label="{{ __('Monitor API setup') }}">
            <x-signal.ui.card class="p-5 lg:col-span-2">
                <p class="ui-eyebrow">{{ __('Authentication') }}</p>
                <h2 class="mt-2 text-lg font-extrabold text-ink">{{ __('Keep credentials server-side') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Use Authorization: Bearer for environment tokens. Existing environment-token clients may also send X-Beacon-Token. Heartbeat and queue keys are scoped to one Monitor, require bearer authentication, and must never be shipped in a browser bundle.') }}</p>
                <x-signal.ui.code-block class="mt-4" :code="'Authorization: Bearer YOUR_ENVIRONMENT_TOKEN'" />
            </x-signal.ui.card>

            <x-signal.ui.card class="p-5">
                <p class="ui-eyebrow">{{ __('Monitor API') }}</p>
                <h2 class="mt-2 text-lg font-extrabold text-ink">{{ __('Base URL') }}</h2>
                <code class="mt-3 block break-all text-sm text-primary">{{ $reference->baseUrl }}</code>
                <p class="mt-5 text-xs font-bold text-muted">{{ __('Machine-readable contract') }}</p>
                <a class="mt-2 block break-all text-xs font-semibold text-primary hover:underline" href="{{ $reference->openApiUrl }}">{{ $reference->openApiUrl }}</a>
            </x-signal.ui.card>
        </section>

        <x-signal.ui.card class="mt-5 p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-ink">{{ __('Version 1 endpoints') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Paths are relative to :url. Reuse the same batch or event identity when retrying.', ['url' => $reference->baseUrl]) }}</p>
                </div>
                <x-signal.ui.badge>{{ __('OpenAPI :version', ['version' => $reference->document['openapi']]) }}</x-signal.ui.badge>
            </div>

            <div class="mt-4 divide-y divide-line border-y border-line">
                @foreach ($reference->document['paths'] as $path => $methods)
                    @foreach ($methods as $method => $operation)
                        <div class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <code class="break-all text-sm font-bold text-ink">{{ strtoupper($method) }} {{ $path }}</code>
                                <p class="mt-1 text-xs leading-5 text-muted">{{ $operation['summary'] }}</p>
                                <p class="mt-1 text-xs leading-5 text-muted">{{ __('Responses: :codes', ['codes' => implode(', ', array_keys($operation['responses'] ?? []))]) }}</p>
                                @if ($operation['x-rate-limits'] ?? [])
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($operation['x-rate-limits'] as $rateLimit)
                                            <x-signal.ui.badge tone="neutral">{{ number_format($rateLimit['requests']) }}/min · {{ $rateLimit['key'] }}</x-signal.ui.badge>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <span class="shrink-0 text-xs font-semibold text-subtle">{{ $operation['operationId'] }}</span>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </x-signal.ui.card>

        <x-signal.ui.card class="mt-5 p-5 sm:p-6">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Quick test') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Create an environment token, then send a redacted connection event. The response includes a receipt you can check while the worker processes the batch.') }}</p>
            <x-signal.ui.code-block class="mt-4" :code="$curlExample" />
        </x-signal.ui.card>

        <x-signal.ui.card class="mt-5 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Outbound alert integration') }}</p>
                    <h2 class="mt-1 text-lg font-extrabold text-ink">{{ __('Signed alert webhooks') }}</h2>
                </div>
                <x-signal.ui.badge tone="info">{{ __('Monitor signs each delivery') }}</x-signal.ui.badge>
            </div>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-muted">{{ __('This receiver contract is separate from the inbound Monitor API above. A custom Webhook alert destination receives signed JSON POST attempts for each routed notification; Slack, Teams, Discord, and PagerDuty use their own provider formats.') }}</p>

            <x-signal.ui.panel as="div" class="mt-4 bg-surface-muted p-4">
                <h3 class="font-bold">{{ __('Verify the request') }}</h3>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Store the destination signing key securely. Read X-Beacon-Timestamp as Unix seconds and compute v1= plus the lowercase hexadecimal HMAC-SHA256 using the key over timestamp + "." + the exact raw request body. Compare it with X-Beacon-Signature in constant time. Reject timestamps outside your short replay window, such as five minutes.') }}</p>
                <x-signal.ui.code-block class="mt-3" :code="$alertWebhookSignatureExample" />
            </x-signal.ui.panel>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <x-signal.ui.panel as="section" class="space-y-2 p-4">
                    <h3 class="font-bold">{{ __('Payload and acknowledgement') }}</h3>
                    <p class="text-sm leading-6 text-muted">{{ __('The stable delivery ID is sent in both X-Beacon-Delivery and the JSON id field. The payload includes event, title, incident_id, application, environment, url, and applicable rule or monitor, observation, opened_at, resolved_at, or escalation details. Events include opened, recovered, escalated, and test.') }}</p>
                    <p class="text-sm leading-6 text-muted">{{ __('Delivery is at least once, so deduplicate by ID. Return any 2xx response to acknowledge it; redirects are not followed. Monitor uses a three-second connection timeout and a ten-second total request timeout.') }}</p>
                </x-signal.ui.panel>
                <x-signal.ui.panel as="section" class="space-y-2 p-4">
                    <h3 class="font-bold">{{ __('Retry and recovery') }}</h3>
                    <p class="text-sm leading-6 text-muted">{{ __('Each automatic delivery cycle makes up to five total webhook attempts. Network failures, HTTP 408, 429, and 5xx responses retry; the default delays are 30 seconds, 2 minutes, 10 minutes, and 30 minutes. A numeric Retry-After on 429 is bounded between 30 seconds and one hour. Other non-2xx responses fail the attempt.') }}</p>
                    <p class="text-sm leading-6 text-muted">{{ __('Review failed or uncertain deliveries in Monitor before using an authorized manual retry. Retry is allowed only while the delivery is still eligible and the destination and incident route remain active. A manual retry starts a new attempt cycle and preserves the delivery ID.') }}</p>
                </x-signal.ui.panel>
            </div>

            @if ($alertDestinationsUrl)
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-signal.ui.button :href="$alertDestinationsUrl" variant="secondary">{{ __('Manage Monitor alert destinations') }}</x-signal.ui.button>
                </div>
            @endif
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
