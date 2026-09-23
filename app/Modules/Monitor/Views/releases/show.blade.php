@extends('monitor::layouts.app')
@section('title', 'Release '.$release->version)
@section('breadcrumb', 'Release detail')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.releases.index', ['application' => $release->application_id]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Releases</a>
    <x-monitor::ui.page-header :eyebrow="$release->application->name.' · '.$release->serviceLabel()" :title="$release->version" description="Compare explicitly reported versions of the same service over one shared time window." />
    <section class="ui-panel overflow-hidden">
        <form method="GET" action="{{ route('monitor.releases.show', $release) }}" class="grid gap-4 border-b border-line p-5 sm:grid-cols-2 lg:grid-cols-4 dark:border-line">
            <x-monitor::ui.select name="environment" label="Environment" :value="$filters['environment'] ?? ''" :options="$environments->pluck('name', 'id')->all()" placeholder="All unarchived environments" />
            <x-monitor::ui.select name="range" label="Source time window" :value="$filters['range']" :options="$rangeOptions" />
            <x-monitor::ui.select name="baseline" label="Compare with version" :value="$filters['baseline'] ?? ''" :options="$baselineOptions->pluck('version', 'id')->all()" placeholder="Choose a baseline" />
            <div class="flex items-end"><x-monitor::ui.button>Update comparison</x-monitor::ui.button></div>
        </form>
        <p class="px-5 py-4 text-xs leading-5 text-muted dark:text-subtle">{{ $from->format('Y-m-d H:i:s') }} — {{ $until->format('Y-m-d H:i:s') }} UTC · baseline choices show the latest 100 catalog entries for this service. No baseline is inferred from version names.</p>
        <x-monitor::ui.release-comparison :left="$baselineMetrics" :right="$currentMetrics" :left-label="$baseline?->version ?? 'No baseline selected'" :right-label="$release->version" />
    </section>
    <section class="ui-panel overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-5 dark:border-line"><h2 class="font-bold">Linked events in this window <span class="text-xs font-normal text-muted">({{ number_format($events->total()) }})</span></h2><a href="{{ route('monitor.events.index', ['release' => $release->id, 'application' => $release->application_id, 'environment' => $filters['environment'] ?? null, 'range' => $filters['range']]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">Open event explorer</a></div>
        <div class="divide-y divide-line dark:divide-line">@forelse($events as $event)<div class="flex flex-col justify-between gap-2 px-5 py-4 sm:flex-row"><div class="min-w-0"><a href="{{ route('monitor.events.show', $event->id) }}" class="block truncate text-sm font-semibold hover:text-primary dark:hover:text-primary">{{ $event->name ?? ucfirst($event->type) }}</a><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $event->environment->name }} · {{ $event->type }} · {{ $event->severity }}</p></div><time class="whitespace-nowrap text-xs text-muted dark:text-subtle" datetime="{{ $event->occurred_at->toISOString() }}">{{ $event->occurred_at->copy()->utc()->format('Y-m-d H:i:s') }} UTC</time></div>@empty<p class="p-6 text-sm text-muted dark:text-subtle">No linked telemetry in this window. This does not establish that the release is healthy.</p>@endforelse</div>
        @if($events->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $events->links() }}</div>@endif
    </section>
    <section class="ui-panel overflow-hidden">
        <div class="border-b border-line p-5 dark:border-line"><h2 class="font-bold">Issues observed in this version</h2><p class="mt-1 text-xs text-muted dark:text-subtle">{{ number_format($issues->total()) }} issues linked to exception records in the selected window. An issue may also occur in other releases; status is its current application-wide triage state.</p></div>
        <div class="divide-y divide-line dark:divide-line">@forelse($issues as $issue)<a href="{{ route('monitor.issues.show', $issue) }}" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-surface-muted dark:hover:bg-surface-muted"><span class="min-w-0 break-words text-sm font-semibold">{{ $issue->title }}</span><x-monitor::ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-monitor::ui.badge></a>@empty<p class="p-6 text-sm text-muted dark:text-subtle">No linked issues in this window.</p>@endforelse</div>
        @if($issues->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $issues->links() }}</div>@endif
    </section>
    <section class="ui-panel overflow-hidden">
        <div class="border-b border-line p-5 dark:border-line"><h2 class="font-bold">Completed deployment records</h2><p class="mt-1 text-xs text-muted dark:text-subtle">All retained dates in the selected environment(s), independent of the telemetry window.</p></div>
        <div class="divide-y divide-line dark:divide-line">@forelse($deployments as $deployment)<a href="{{ route('monitor.deployments.show', [$release->application_id, $deployment->environment_id, $deployment->id]) }}" class="flex flex-wrap justify-between gap-3 px-5 py-4 text-sm hover:bg-surface-muted dark:hover:bg-surface-muted"><span class="font-semibold">{{ $deployment->environment->name }}</span><span class="text-xs text-muted dark:text-subtle">{{ $deployment->deployed_at->utc()->format('Y-m-d H:i:s') }} UTC · {{ $deployment->source === 'api' ? 'API' : 'Manual' }}</span></a>@empty<p class="p-6 text-sm text-muted dark:text-subtle">No deployment recorded for this version. Observing telemetry does not create a deployment.</p>@endforelse</div>
        @if($deployments->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $deployments->links() }}</div>@endif
    </section>
</div>
@endsection
