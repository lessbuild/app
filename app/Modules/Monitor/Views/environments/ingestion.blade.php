@extends('monitor::layouts.app')
@section('title', 'Ingestion · '.$environment->name)
@section('breadcrumb', 'Ingestion diagnostics')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.environments.show', [$application, $environment]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← {{ $application->name }} / {{ $environment->name }}</a>
    <x-monitor::ui.page-header :eyebrow="$application->name.' · '.$environment->name" title="Ingestion diagnostics" description="Follow each delivery from durable acceptance to searchable events. Queued data is redacted and encrypted; usage is recorded only when processing completes.">
        <x-slot:actions><x-monitor::ui.button :href="route('monitor.environments.ingestion', [$application, $environment, ...array_intersect_key($filters, ['status' => true, 'page' => true])])" variant="secondary">Refresh</x-monitor::ui.button></x-slot:actions>
    </x-monitor::ui.page-header>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach($statuses as $status)
            <x-signal.ui.card as="a" href="{{ route('monitor.environments.ingestion', [$application, $environment, 'status' => $status->value]) }}" class="p-5 transition hover:border-primary dark:hover:border-primary">
                <x-monitor::ui.badge :tone="$status->tone()">{{ $status->label() }}</x-monitor::ui.badge>
                <p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($totals->get($status->value, 0)) }}</p>
            </x-signal.ui.card>
        @endforeach
    </div>
    <x-signal.ui.panel as="section" class="overflow-hidden">
        <div class="flex flex-col justify-between gap-4 border-b border-line p-6 lg:flex-row lg:items-end dark:border-line">
            <div><h2 class="font-bold">Delivery history</h2><p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Status totals cover all retained receipts in this environment. This page refreshes manually.</p></div>
            <form method="GET" action="{{ route('monitor.environments.ingestion', [$application, $environment]) }}" class="flex flex-wrap items-end gap-3">
                <x-monitor::ui.select id="receipt-status" name="status" label="Processing status" :value="$filters['status'] ?? ''" placeholder="All statuses" :options="collect($statuses)->mapWithKeys(fn ($status): array => [$status->value => $status->label()])->all()" :restore="false" />
                <x-monitor::ui.button variant="secondary">Filter</x-monitor::ui.button>
            </form>
        </div>
        @if($receipts->isNotEmpty())
            <div class="overflow-x-auto">
                <x-monitor::ui.table caption="New and duplicate counts describe the original delivery. Delivery attempts include collector replays; processing attempts count worker executions. Replays and processing retries do not add duplicate usage. All times are UTC." :framed="false">
                    <x-slot:head><tr><th scope="col" class="px-6 font-semibold">Receipt / received</th><th scope="col" class="font-semibold">State</th><th scope="col" class="font-semibold">Events</th><th scope="col" class="font-semibold">Attempts</th><th scope="col" class="px-6 font-semibold">Processing / action</th></tr></x-slot:head>
                        @foreach($receipts as $receipt)
                            <tr>
                                <th scope="row" class="px-6 align-top font-normal"><code class="whitespace-nowrap text-[11px]">{{ $receipt->id }}</code><time datetime="{{ $receipt->received_at->toISOString() }}" class="mt-2 block whitespace-nowrap text-muted dark:text-subtle">{{ $receipt->received_at->utc()->format('Y-m-d H:i:s') }}</time><p class="mt-1 text-muted dark:text-subtle">{{ $receipt->source->label() }}</p></th>
                                <td class="align-top"><x-monitor::ui.badge :tone="$receipt->status->tone()">{{ $receipt->status->label() }}</x-monitor::ui.badge>@if($receipt->processingError())<p class="mt-2 min-w-48 max-w-xs leading-5 text-muted dark:text-subtle">{{ $receipt->processingError() }}</p>@endif</td>
                                <td class="whitespace-nowrap align-top tabular-nums"><p>{{ number_format($receipt->accepted_count) }} new</p><p class="mt-2 text-muted dark:text-subtle">{{ number_format($receipt->duplicate_count) }} duplicate</p></td>
                                <td class="whitespace-nowrap align-top tabular-nums"><p>{{ number_format($receipt->attempt_count) }} delivery</p><p class="mt-2 text-muted dark:text-subtle">{{ number_format($receipt->processing_attempts) }} processing</p><p class="mt-1 text-muted dark:text-subtle">{{ number_format($receipt->recovery_count) }} recovery</p></td>
                                <td class="px-6 align-top">
                                    @if($receipt->processed_at)
                                        <p class="whitespace-nowrap text-muted dark:text-subtle">Completed {{ $receipt->processed_at->utc()->format('Y-m-d H:i:s') }}</p>
                                    @elseif($receipt->status->value === 'failed')
                                        @if($receipt->failed_at)<p class="whitespace-nowrap text-muted dark:text-subtle">Failed {{ $receipt->failed_at->utc()->format('Y-m-d H:i:s') }}</p>@endif
                                        @if($receipt->payload_available && $canRetry)
                                            <form method="POST" action="{{ route('monitor.environments.ingestion.retry', [$application, $environment, $receipt]) }}" class="mt-3" data-confirm="Retry this retained delivery? Previously completed events will not be charged again.">@csrf<x-monitor::ui.button variant="secondary">Retry delivery</x-monitor::ui.button></form>
                                        @elseif(! $receipt->payload_available)
                                            <p class="mt-2 text-muted dark:text-subtle">No retained payload to retry.</p>
                                        @else
                                            <p class="mt-2 text-muted dark:text-subtle">An owner or admin can retry after the source is restored.</p>
                                        @endif
                                    @elseif($receipt->next_attempt_at)
                                        <p class="text-muted dark:text-subtle">{{ $receipt->status->value === 'processing' ? 'Lease expires' : 'Eligible after' }}</p><p class="mt-1 whitespace-nowrap">{{ $receipt->next_attempt_at->utc()->format('Y-m-d H:i:s') }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                </x-monitor::ui.table>
            </div>
            @if($receipts->hasPages())<div class="border-t border-line p-6 dark:border-line">{{ $receipts->links() }}</div>@endif
        @else
            <div class="flex flex-col gap-2 p-8 text-center"><h3 class="text-sm font-semibold">No matching deliveries</h3><p class="text-sm text-muted dark:text-subtle">Send telemetry or choose another status. Rejected requests and empty OTLP exports do not create receipts.</p></div>
        @endif
    </x-signal.ui.panel>
    <p class="text-xs leading-6 text-muted dark:text-subtle">Temporary failures retry automatically with backoff, up to five processing attempts. Failed deliveries retain their encrypted payload for investigation and manual retry. Successfully processed payloads are removed from the pending store. Pausing or archiving a source blocks new deliveries; already accepted deliveries still finish processing.</p>
</div>
@endsection
