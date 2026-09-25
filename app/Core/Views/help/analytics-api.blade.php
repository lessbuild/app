<x-signal.layouts.core
    :title="__('Analytics API reference') . ' · Buildpusher'"
    :description="__('Versioned Analytics tracker and event collection API documentation with an OpenAPI contract.')"
    :canonical="route('core.help.analytics.api')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation active-product="analytics" />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl px-5 py-12 sm:px-8 sm:py-16">
        <div class="mx-auto max-w-5xl">
            <x-signal.ui.page-header
                eyebrow="{{ __('Buildpusher Analytics · API v1') }}"
                :title="__('Tracker and collection API')"
                :description="__('Install the browser tracker or send bounded event batches from your own collector. Collection is public-ID based and protected by registered site origins, server-side validation, and workspace availability checks.')"
            >
                <x-slot:actions>
                    <x-signal.ui.button :href="route('core.help')" variant="secondary">{{ __('All help and guides') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="$reference->openApiUrl" variant="primary" target="_blank" rel="noreferrer">{{ __('OpenAPI 3.1 JSON') }}</x-signal.ui.button>
                </x-slot:actions>
            </x-signal.ui.page-header>

            <section class="mt-7 grid gap-4 lg:grid-cols-3" aria-label="{{ __('Analytics API basics') }}">
                <x-signal.ui.card class="p-5 lg:col-span-2">
                    <p class="ui-eyebrow">{{ __('Browser tracker') }}</p>
                    <h2 class="mt-2 text-lg font-extrabold">{{ __('Install the versioned script') }}</h2>
                    <x-signal.ui.code-block class="mt-4" :code="$trackerSnippet" />
                    <p class="mt-3 text-sm leading-6 text-muted">{{ __('Replace SITE_PUBLIC_ID with the value shown in site setup. The tracker honors the opt-out flag window.buildpusherAnalyticsOptOut and can use a consent callback through data-consent. Keep site domains current so browser requests pass the origin check.') }}</p>
                </x-signal.ui.card>
                <x-signal.ui.card class="p-5">
                    <p class="ui-eyebrow">{{ __('Collection endpoint') }}</p>
                    <h2 class="mt-2 text-lg font-extrabold">{{ __('Base URL') }}</h2>
                    <code class="mt-3 block break-all text-sm text-primary">{{ $reference->baseUrl }}</code>
                    <p class="mt-5 text-xs font-bold text-muted">{{ __('POST endpoint') }}</p>
                    <code class="mt-2 block break-all text-xs text-primary">{{ $reference->ingestUrl }}</code>
                </x-signal.ui.card>
            </section>

            <x-signal.ui.card class="mt-5 p-5 sm:p-6">
                <h2 class="text-lg font-extrabold">{{ __('Server-side collection example') }}</h2>
                <p class="mt-1 text-sm leading-6 text-muted">{{ __('Send at most 20 events in a request no larger than 32,768 bytes. Keep each UUID unchanged on retry; duplicate IDs are ignored. The response acknowledges acceptance, not completed report processing.') }}</p>
                <x-signal.ui.code-block class="mt-4" :code="$curlExample" />
                <p class="mt-3 text-xs leading-5 text-muted">{{ __('No bearer token is sent. The public site ID is not a secret; every event is validated, site-origin rules are enforced when Origin is present, and workspace collection availability is checked.') }}</p>
            </x-signal.ui.card>

            <x-signal.ui.card class="mt-5 p-5 sm:p-6">
                <h2 class="text-lg font-extrabold">{{ __('Version 1 operations') }}</h2>
                <div class="mt-4 divide-y divide-line border-y border-line">
                    @foreach ($reference->document['paths'] as $path => $methods)
                        @foreach ($methods as $method => $operation)
                            <div class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <code class="break-all text-sm font-bold text-ink">{{ strtoupper($method) }} {{ $path }}</code>
                                    <p class="mt-1 text-xs leading-5 text-muted">{{ $operation['summary'] }}</p>
                                    <p class="mt-1 text-xs leading-5 text-muted">{{ __('Responses: :codes', ['codes' => implode(', ', array_keys($operation['responses'] ?? []))]) }}</p>
                                    @if (isset($operation['x-max-body-bytes']))
                                        <p class="mt-1 text-xs leading-5 text-muted">{{ __('Maximum request body') }}: {{ number_format($operation['x-max-body-bytes']) }} {{ __('bytes') }}</p>
                                    @endif
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
        </div>
    </main>
</x-signal.layouts.core>
