@extends('monitor::layouts.app')
@section('title', 'Releases')
@section('breadcrumb', 'Releases')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="Change intelligence" title="Releases" description="Connect reported versions with telemetry and completed deployments." />
    <x-signal.ui.panel as="section" class="overflow-hidden">
        <form method="GET" action="{{ route('monitor.releases.index') }}" class="grid gap-4 border-b border-line p-5 sm:grid-cols-3 dark:border-line">
            <x-monitor::ui.input name="q" label="Version, service or namespace" type="search" :value="$filters['q'] ?? ''" maxlength="255" />
            <x-monitor::ui.select name="application" label="Application" :value="$filters['application'] ?? ''" :options="$applications->pluck('name', 'id')->all()" placeholder="All applications" />
            <div class="flex items-end gap-4"><x-monitor::ui.button>Filter releases</x-monitor::ui.button><a href="{{ route('monitor.releases.index') }}" class="py-2 text-xs text-muted hover:underline dark:text-subtle">Reset</a></div>
        </form>
        <p class="px-5 py-4 text-xs text-muted dark:text-subtle">{{ number_format($releases->total()) }} matching releases · newest catalog entry first · manual refresh</p>
        <div class="overflow-x-auto"><x-monitor::ui.table caption="Releases and deployments" :framed="false">
            <x-slot:head><tr><th scope="col">Release / application</th><th scope="col">Service</th><th scope="col">First source event (UTC)</th><th scope="col">Last source event (UTC)</th></tr></x-slot:head>
                @forelse($releases as $release)
                    <tr><th scope="row" class="max-w-xs text-left font-normal"><a href="{{ route('monitor.releases.show', $release) }}" class="block truncate text-sm font-bold text-primary hover:underline dark:text-primary">{{ $release->version }}</a><p class="mt-1 text-muted dark:text-subtle">{{ $release->application->name }}</p></th><td class="max-w-xs break-words">{{ $release->serviceLabel() }}</td><td class="whitespace-nowrap">{{ $release->first_seen_at?->utc()->format('Y-m-d H:i:s') ?? 'No linked telemetry' }}</td><td class="whitespace-nowrap">{{ $release->last_seen_at?->utc()->format('Y-m-d H:i:s') ?? 'No linked telemetry' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="py-12 text-center text-muted dark:text-subtle">No releases yet. Report a service.version with telemetry or record a deployment from an environment.</td></tr>
                @endforelse
        </x-monitor::ui.table></div>
        @if($releases->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $releases->links() }}</div>@endif
    </x-signal.ui.panel>
    <p class="text-xs leading-5 text-muted dark:text-subtle">Versions are case-sensitive and separate for each application, service and namespace. Source timestamps are lifetime observations, including archived environments, not deployment times. Historical events are not backfilled.</p>
    <x-signal.ui.panel as="section" class="p-5"><h2 class="font-bold">Attribute telemetry to a version</h2><p class="mt-2 text-sm leading-6 text-muted dark:text-subtle">For JSON events, send attributes["service.version"], the top-level service name and optionally attributes["service.namespace"]. For OTLP, set service.version, service.name and service.namespace on the resource. Missing, invalid or redacted labels remain unlinked. Recording a deployment never assigns unversioned events by time.</p></x-signal.ui.panel>
</div>
@endsection
