@extends('monitor::layouts.app')
@section('title', 'Integrations')
@section('breadcrumb', 'Integrations')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="Developer setup" eyebrow-icon="code" title="Connect your stack." description="Choose an environment, issue a private token, and verify telemetry from any application or infrastructure service.">
        <x-slot:actions>
            <x-monitor::ui.button :href="route('monitor.applications.create')">Add application</x-monitor::ui.button>
            <x-monitor::ui.button :href="route('monitor.settings.api')">API reference</x-monitor::ui.button>
        </x-slot:actions>
    </x-monitor::ui.page-header>

    <x-monitor::ui.card padding="p-0" :shadow="false" class="overflow-hidden">
        <div class="bg-surface-muted p-6 sm:p-8">
            <span class="text-xs font-bold uppercase tracking-wider text-primary">Any language. Your telemetry.</span>
            <h2 class="mt-3 text-2xl font-bold tracking-tight">Start with a single HTTP request.</h2>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-muted">{{ config('app.name') }} accepts versioned JSON events and OpenTelemetry over HTTP/JSON. Choose the runtime closest to your service for a copyable first delivery, then use the environment connection check to confirm processing.</p>
        </div>
        <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.7fr)] lg:items-end sm:p-8">
            <form method="GET" action="{{ route('monitor.settings.integrations') }}" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <x-monitor::ui.select name="stack" label="Choose your stack" :value="$selectedStack" :options="$stackOptions" />
                <x-monitor::ui.button>Show setup guide</x-monitor::ui.button>
            </form>
            <x-signal.ui.alert as="div" tone="info" class="border-primary/30 bg-primary-soft block p-4">
                <p class="text-xs font-bold text-primary dark:text-primary">Selected guide</p>
                <p class="mt-2 text-sm font-semibold text-primary dark:text-primary">{{ $setupGuide['label'] }}</p>
                <p class="mt-1 text-xs leading-5 text-primary dark:text-primary">{{ $setupGuide['install'] }}</p>
            </x-signal.ui.alert>
        </div>
    </x-monitor::ui.card>

    <div class="grid gap-5 lg:grid-cols-3">
        <x-monitor::ui.card>
            <div class="flex items-center gap-3"><x-monitor::ui.badge tone="violet">1</x-monitor::ui.badge><h2 class="font-bold">Create a private credential</h2></div>
            <p class="mt-4 text-sm leading-6 text-muted dark:text-subtle">Open an environment below and create a token. {{ config('app.name') }} shows the full secret once, so store it in your deployment secret manager.</p>
            <div class="mt-5">
                <x-monitor::ui.code-block id="integration-token-code" language="Secret">
BEACON_TOKEN="paste-your-environment-token-here"
                </x-monitor::ui.code-block>
            </div>
            <p class="mt-4 text-xs leading-5 text-muted dark:text-subtle">{{ $setupGuide['token'] }}</p>
        </x-monitor::ui.card>

        <x-monitor::ui.card class="lg:col-span-2">
            <div class="flex items-center gap-3"><x-monitor::ui.badge tone="violet">2</x-monitor::ui.badge><h2 class="font-bold">Send a test event</h2></div>
            <p class="mt-4 text-sm leading-6 text-muted dark:text-subtle">Use the same environment token in the server-side process that owns the telemetry. The example is safe to retry with a new batch ID.</p>
            <div class="mt-5">
                <x-monitor::ui.code-block id="integration-example-code" :language="$setupGuide['label']">
{{ $setupGuide['code'] }}
                </x-monitor::ui.code-block>
            </div>
        </x-monitor::ui.card>
    </div>

    <x-monitor::ui.card>
        <div class="flex items-center gap-3"><x-monitor::ui.badge tone="violet">OTel</x-monitor::ui.badge><h2 class="font-bold">Connect an OpenTelemetry SDK</h2></div>
        <p class="mt-4 max-w-3xl text-sm leading-6 text-muted dark:text-subtle">Use OTLP/HTTP with JSON encoding and the full endpoint for each signal. Replace the token placeholder with the private token for the environment receiving this telemetry. Support for the <code class="text-xs">http/json</code> protocol varies by SDK; if yours only supports protobuf or gRPC, configure an OpenTelemetry Collector to translate and forward the data.</p>
        <div class="mt-5">
            <x-monitor::ui.code-block id="otlp-environment-code" language=".env">
{{ $otlpConfiguration }}
            </x-monitor::ui.code-block>
        </div>
    </x-monitor::ui.card>

    <x-monitor::ui.card>
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
            <div>
                <div class="flex items-center gap-3"><x-monitor::ui.badge tone="violet">3</x-monitor::ui.badge><h2 class="font-bold">Verify processing</h2></div>
                <p class="mt-4 max-w-3xl text-sm leading-6 text-muted dark:text-subtle">{{ $setupGuide['verification'] }}</p>
            </div>
            <a href="#environments" class="shrink-0 text-xs font-bold text-primary hover:underline dark:text-primary">Choose an environment →</a>
        </div>
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="rounded-control bg-surface-muted p-4 dark:bg-surface-muted"><p class="text-xs font-bold">Delivery endpoint</p><code class="mt-2 block break-all text-[11px] text-muted dark:text-subtle">{{ route('monitor.api.ingest') }}</code></div>
            <div class="rounded-control bg-surface-muted p-4 dark:bg-surface-muted"><p class="text-xs font-bold">Receipt endpoint</p><code class="mt-2 block break-all text-[11px] text-muted dark:text-subtle">{{ $setupGuide['receipt_endpoint'] }}</code></div>
        </div>
    </x-monitor::ui.card>

    <div id="environments" class="scroll-mt-6 grid gap-5 lg:grid-cols-2">
        @forelse($applications as $application)
            <x-monitor::ui.card padding="p-0" :shadow="false" class="overflow-hidden">
                <div class="flex items-center gap-3 border-b border-line p-5 dark:border-line"><x-monitor::ui.application-mark :application="$application" /><div><h2 class="text-sm font-bold">{{ $application->name }}</h2><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $application->framework }}</p></div></div>
                <div class="divide-y divide-line dark:divide-line">
                    @forelse($application->environments as $environment)
                        <a href="{{ route('monitor.environments.show', [$application, $environment]) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-surface-muted dark:hover:bg-surface-muted"><div><p class="text-sm font-semibold">{{ $environment->name }}</p><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $environment->status === 'paused' ? 'Paused' : ($environment->last_seen_at ? 'Last event '.$environment->last_seen_at->diffForHumans() : 'Awaiting your first event') }}</p></div><span class="text-xs font-bold text-primary dark:text-primary">Setup →</span></a>
                    @empty
                        <p class="p-5 text-sm text-muted dark:text-subtle">Add an environment from <a href="{{ route('monitor.applications.show', $application) }}" class="text-primary underline dark:text-primary">application settings</a>.</p>
                    @endforelse
                </div>
            </x-monitor::ui.card>
        @empty
            <x-monitor::ui.empty-state class="lg:col-span-2" icon="code" title="No application connected" description="Create an application to get a production environment and your first private token.">
                <x-slot:action><a href="{{ route('monitor.applications.create') }}" class="mt-3 inline-block text-sm font-bold text-primary hover:underline dark:text-primary">Create your first application →</a></x-slot:action>
            </x-monitor::ui.empty-state>
        @endforelse
    </div>
    {{ $applications->links() }}

    <x-monitor::ui.card>
        <h2 class="font-bold">Safe collection starts at the source</h2>
        <div class="mt-4 grid gap-5 text-sm leading-6 text-muted sm:grid-cols-3 dark:text-subtle"><p><span class="font-semibold text-ink dark:text-ink">Keep keys server-side.</span><br>Use your secret manager, never a public frontend bundle.</p><p><span class="font-semibold text-ink dark:text-ink">Remove sensitive data.</span><br>Redact credentials and personal data before transmission.</p><p><span class="font-semibold text-ink dark:text-ink">Retry safely.</span><br>Reuse batch and event IDs when retrying the same JSON events.</p></div>
    </x-monitor::ui.card>
</div>
@endsection
