@extends('monitor::layouts.app')

@section('title', 'API reference')
@section('breadcrumb', 'API reference')

@section('content')
    <div class="space-y-6">
        <x-monitor::ui.page-header eyebrow="Developer API" eyebrow-icon="code" title="One contract for every stack." description="Use the versioned HTTP API for native clients, collectors and OpenTelemetry exporters. The machine-readable contract is safe to publish and contains no workspace secrets.">
            <x-slot:actions>
                <x-monitor::ui.button :href="$openApiUrl" target="_blank" rel="noreferrer" class="gap-2" variant="secondary"><x-monitor::icon name="external" class="h-3.5 w-3.5" />OpenAPI JSON</x-monitor::ui.button>
            </x-slot:actions>
        </x-monitor::ui.page-header>

        <div class="grid gap-5 lg:grid-cols-3">
            <x-monitor::ui.card class="lg:col-span-2">
                <div class="flex items-center gap-3"><x-monitor::ui.badge tone="violet">Authentication</x-monitor::ui.badge><h2 class="font-bold">Keep credentials server-side</h2></div>
                <p class="mt-4 text-sm leading-6 text-muted dark:text-subtle">Environment tokens use the standard Authorization header. Monitor-specific heartbeat and queue keys use the same bearer format but are scoped to one monitor. Never ship any of these keys in a browser bundle.</p>
                <x-monitor::ui.code-block id="api-auth-example" language="HTTP" class="mt-5">
Authorization: Bearer YOUR_ENVIRONMENT_TOKEN
Content-Type: application/json
                </x-monitor::ui.code-block>
            </x-monitor::ui.card>
            <x-monitor::ui.card>
                <p class="text-xs font-bold text-muted dark:text-subtle">Base URL</p>
                <code class="mt-3 block break-all text-xs text-primary dark:text-primary">{{ $document['servers'][0]['url'] }}</code>
                <p class="mt-5 text-xs font-bold text-muted dark:text-subtle">JSON contract</p>
                <code class="mt-3 block break-all text-xs text-primary dark:text-primary">{{ $openApiUrl }}</code>
                <a href="{{ route('monitor.settings.integrations') }}" class="mt-5 inline-flex text-xs font-bold text-primary hover:underline dark:text-primary">Stack quickstarts →</a>
            </x-monitor::ui.card>
        </div>

        <x-monitor::ui.card>
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end"><div><h2 class="font-bold">Version 1 endpoints</h2><p class="mt-1 text-xs text-muted dark:text-subtle">All paths are relative to {{ $document['servers'][0]['url'] }}. Retries should reuse the same batch or event identity.</p></div><x-monitor::ui.badge tone="green">OpenAPI {{ $document['openapi'] }}</x-monitor::ui.badge></div>
            <div class="mt-5 divide-y divide-line dark:divide-line">
                @foreach($document['paths'] as $path => $methods)
                    @foreach($methods as $method => $operation)
                        <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between"><div class="flex min-w-0 items-start gap-3"><span class="inline-flex w-16 shrink-0 justify-center rounded-control bg-surface-muted px-2 py-1 text-[10px] font-bold uppercase text-muted dark:bg-surface-muted dark:text-muted">{{ $method }}</span><div class="min-w-0"><code class="break-all text-sm font-semibold text-ink dark:text-ink">{{ $path }}</code><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">{{ $operation['summary'] }}</p></div></div><span class="shrink-0 text-[11px] font-semibold text-subtle">{{ $operation['operationId'] }}</span></div>
                    @endforeach
                @endforeach
            </div>
        </x-monitor::ui.card>

        <x-monitor::ui.card>
            <h2 class="font-bold">Quick test</h2>
            <p class="mt-2 text-sm leading-6 text-muted dark:text-subtle">Create an environment token, then send a redacted connection event. The response includes a receipt that can be checked while the worker processes the batch.</p>
            <x-monitor::ui.code-block id="api-curl-example" language="cURL" class="mt-5">
curl --request POST '{{ $ingestUrl }}' --header 'Authorization: Bearer YOUR_ENVIRONMENT_TOKEN' --header 'Content-Type: application/json' --data '{"batch_id":"connection-test-1","events":[{"id":"connection-test-1","type":"log","name":"Connection test","service":"my-service","severity":"info"}]}'
            </x-monitor::ui.code-block>
        </x-monitor::ui.card>
    </div>
@endsection
