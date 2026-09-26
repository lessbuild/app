@extends('monitor::layouts.app')
@section('title', 'Metrics')
@section('breadcrumb', 'Metrics')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="Infrastructure & application telemetry" title="Metrics" description="Explore resource-specific numeric series from any stack. A series keeps its host, container, database or custom labels separate, so unrelated resources are never averaged together.">
        <x-slot:actions><x-monitor::ui.button :href="route('monitor.metrics.index', $filters)" variant="secondary">Refresh metrics</x-monitor::ui.button></x-slot:actions>
    </x-monitor::ui.page-header>
    <x-signal.ui.panel as="section" class="space-y-5 p-5">
        <form method="GET" action="{{ route('monitor.metrics.index') }}" class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
            <x-monitor::ui.input name="q" label="Metric, unit or resource" type="search" :value="$filters['q'] ?? ''" maxlength="255" placeholder="system.memory.usage or api-1" />
            <x-monitor::ui.select name="environment" label="Environment" :value="$filters['environment'] ?? ''" :options="$environmentOptions" placeholder="All environments" />
            <x-monitor::ui.select name="kind" label="Metric type" :value="$filters['kind'] ?? ''" :options="['gauge' => 'Gauge', 'sum' => 'Sum', 'histogram' => 'Histogram', 'exponentialHistogram' => 'Exponential histogram', 'summary' => 'Summary']" placeholder="All types" />
            <div class="flex items-end gap-3"><x-monitor::ui.button>Filter</x-monitor::ui.button><a href="{{ route('monitor.metrics.index') }}" class="py-2 text-xs text-muted hover:underline dark:text-subtle">Reset</a></div>
        </form>
        <p class="text-xs text-muted dark:text-subtle">{{ number_format($series->total()) }} metric series · newest sample first · raw samples remain available in Events & logs</p>
        <div class="overflow-x-auto"><x-monitor::ui.table caption="Metric series and resources" :framed="false">
            <x-slot:head><tr><th scope="col">Metric</th><th scope="col">Resource</th><th scope="col">Type / unit</th><th scope="col">Last received (UTC)</th><th scope="col"></th></tr></x-slot:head>
                @forelse($series as $item)
                    <tr class="hover:bg-surface-muted dark:hover:bg-surface-muted"><th scope="row" class="max-w-sm"><a href="{{ route('monitor.metrics.show', $item) }}" class="break-words text-sm font-bold text-primary hover:underline dark:text-primary">{{ $item->name }}</a><p class="mt-1 text-[11px] text-muted dark:text-subtle">{{ $item->environment->application->name }} / {{ $item->environment->name }}</p></th><td class="max-w-xs break-words font-mono text-[11px]">{{ $item->resource_label }}</td><td class="whitespace-nowrap"><span class="font-semibold">{{ $item->kind }}</span><span class="ml-1 text-muted dark:text-subtle">{{ $item->unit ?: 'unitless' }}</span></td><td class="whitespace-nowrap text-muted dark:text-subtle">{{ $item->last_received_at->utc()->format('Y-m-d H:i:s.u') }}</td><td class="whitespace-nowrap"><a href="{{ route('monitor.alerts.create', ['series' => $item->id]) }}" class="font-bold text-primary hover:underline dark:text-primary">Create alert</a></td></tr>
                @empty
                    <tr><td colspan="5" class="py-14 text-center"><p class="text-sm font-semibold">No metric series yet</p><p class="mt-2 text-xs text-muted dark:text-subtle">Send OTLP metrics or JSON events with a numeric payload, then refresh this page.</p></td></tr>
                @endforelse
        </x-monitor::ui.table></div>
        @if($series->hasPages())<div class="border-t border-line pt-5 dark:border-line">{{ $series->links() }}</div>@endif
    </x-signal.ui.panel>
    <section class="space-y-4">
        <div><h2 class="text-xl font-bold">Collector setup profiles</h2><p class="mt-1 text-sm text-muted dark:text-subtle">These profiles use the OpenTelemetry Collector and send JSON OTLP metrics to this workspace. Replace the environment variables and set the token as a secret.</p></div>
        <div class="grid gap-5 xl:grid-cols-2">
            @foreach($profiles as $profile)
                <x-signal.ui.card as="article" class="overflow-hidden"><div class="border-b border-line p-5 dark:border-line"><div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-bold">{{ $profile['label'] }}</h3><x-monitor::ui.badge tone="slate">{{ $profile['stability'] }}</x-monitor::ui.badge></div><p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">{{ $profile['requirements'] }}</p></div><pre class="library-code max-h-96 rounded-none"><code>{{ $profile['yaml'] }}</code></pre></x-signal.ui.card>
            @endforeach
        </div>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Collector component maturity is shown as published by OpenTelemetry. Docker Stats and Oracle DB receiver metrics are alpha. AWS CloudWatch receiver metrics are alpha; its profiles query explicit EC2, Lambda, RDS-instance or SQS-queue metrics and do not collect CloudWatch Logs. SQS metrics are approximate and may be missing while a queue is inactive. The RDS profile excludes Aurora cluster metrics, Enhanced Monitoring and Performance Insights. Set the Lambda metric delay longer than the function runtime plus CloudWatch publication latency. PostgreSQL query sampling and optional MySQL query-sample logs require additional database privileges and are not enabled by these baseline profiles. Use HTTPS before installing real production credentials.</p>
    </section>
</div>
@endsection
