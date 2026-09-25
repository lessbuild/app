@extends('monitor::layouts.app')
@section('title', 'Deployment '.$deployment->release->version)
@section('breadcrumb', 'Deployment detail')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.deployments.index', [$application, $environment]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Deployment history</a>
    <x-monitor::ui.page-header :eyebrow="$application->name.' / '.$environment->name.' · '.$deployment->release->serviceLabel()" :title="$deployment->release->version" :description="'Reported completed '.$deployment->deployed_at->utc()->format('Y-m-d H:i:s.u').' UTC'" />
    <x-signal.ui.panel as="section" class="p-5">
        <dl class="grid gap-5 text-xs sm:grid-cols-2"><div><dt class="text-muted dark:text-subtle">Recorded by</dt><dd class="mt-2 font-semibold">{{ match ($deployment->source) { 'api' => 'Environment API token', 'integration' => 'Deployer integration', default => $deployment->actor?->name ?? 'Removed contributor' } }}</dd></div><div><dt class="text-muted dark:text-subtle">Commit ID</dt><dd class="mt-2 break-all font-mono">{{ $deployment->commit_sha ?? 'Not reported' }}</dd></div><div><dt class="text-muted dark:text-subtle">Deployment UUID</dt><dd class="mt-2 break-all font-mono">{{ $deployment->deployment_key }}</dd></div><div><dt class="text-muted dark:text-subtle">Received (UTC)</dt><dd class="mt-2">{{ $deployment->created_at->copy()->utc()->format('Y-m-d H:i:s') }}</dd></div></dl>
        @if($note !== null)<p class="mt-5 break-words border-t border-line pt-5 text-sm dark:border-line">{{ $note }}</p>@endif
        <a href="{{ route('monitor.releases.show', ['release' => $deployment->release_id, 'environment' => $environment->id]) }}" class="mt-5 inline-block text-xs font-bold text-primary hover:underline dark:text-primary">Compare this version and inspect its events →</a>
    </x-signal.ui.panel>
    <x-signal.ui.panel as="section" class="overflow-hidden">
        <form method="GET" action="{{ route('monitor.deployments.show', [$application, $environment, $deployment]) }}" class="flex flex-wrap items-end gap-4 border-b border-line p-5 dark:border-line"><x-monitor::ui.select name="window" label="Maximum window on each side" :value="$filters['window']" :options="$windowOptions" /><x-monitor::ui.button>Compare windows</x-monitor::ui.button></form>
        <div class="p-5"><h2 class="font-bold">Before and after this deployment</h2><p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">Same environment and service identity, across all explicitly reported versions. Overlapping deployments and rollbacks can affect both windows. This is a time comparison, not an attribution of failures to this deployment.</p>
        @if($comparison['seconds'] > 0)<p class="mt-2 text-xs text-muted dark:text-subtle">Equal {{ number_format($comparison['seconds']) }}-second windows: {{ $comparison['from']->format('Y-m-d H:i:s.u') }} → {{ $comparison['deployedAt']->format('Y-m-d H:i:s.u') }} → {{ $comparison['until']->format('Y-m-d H:i:s.u') }} UTC. Start inclusive, end exclusive; recent deployments use shorter windows.</p>@else<p class="mt-2 text-sm text-warning dark:text-warning">Awaiting an elapsed comparison window. Refresh after the reported deployment time.</p>@endif</div>
        <x-monitor::ui.release-comparison :left="$comparison['before']" :right="$comparison['after']" left-label="Before deployment" right-label="After deployment" />
        @if($comparison['seconds'] > 0)
            <div class="grid gap-5 border-t border-line p-5 sm:grid-cols-2 dark:border-line">
                <section aria-labelledby="overlapping-releases-heading">
                    <h3 id="overlapping-releases-heading" class="text-sm font-bold">Other deployments during this window</h3>
                    <div class="mt-3 space-y-3">
                        @forelse($nearbyDeployments as $nearby)
                            <a href="{{ route('monitor.deployments.show', [$application, $environment, $nearby]) }}" class="block rounded-card border border-line p-3 hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus dark:border-line">
                                <span class="block text-sm font-semibold">{{ $nearby->release->version }} · {{ $nearby->release->serviceLabel() }}</span>
                                <time class="mt-1 block text-xs text-muted dark:text-subtle" datetime="{{ $nearby->deployed_at->toISOString() }}">{{ $nearby->deployed_at->utc()->format('Y-m-d H:i:s.u') }} UTC</time>
                            </a>
                        @empty
                            <p class="text-xs text-muted dark:text-subtle">No other same-service deployment was recorded in these windows.</p>
                        @endforelse
                    </div>
                </section>
                <section aria-labelledby="overlapping-incidents-heading">
                    <h3 id="overlapping-incidents-heading" class="text-sm font-bold">Incidents overlapping these windows</h3>
                    <div class="mt-3 space-y-3">
                        @forelse($overlappingIncidents as $overlappingIncident)
                            <a href="{{ route('monitor.incidents.show', $overlappingIncident) }}" class="block rounded-card border border-line p-3 hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus dark:border-line">
                                <span class="block text-sm font-semibold">#{{ $overlappingIncident->id }} · {{ $overlappingIncident->title }}</span>
                                <span class="mt-1 block text-xs text-muted dark:text-subtle">{{ ucfirst($overlappingIncident->status) }} · Opened {{ $overlappingIncident->opened_at->utc()->format('Y-m-d H:i:s') }} UTC{{ $overlappingIncident->resolved_at ? ' · Resolved '.$overlappingIncident->resolved_at->utc()->format('Y-m-d H:i:s').' UTC' : '' }}</span>
                            </a>
                        @empty
                            <p class="text-xs text-muted dark:text-subtle">No incident interval overlaps this comparison window.</p>
                        @endforelse
                    </div>
                </section>
            </div>
        @endif
    </x-signal.ui.panel>
    <x-signal.ui.panel as="section" class="overflow-hidden">
        <div class="border-b border-line p-5 dark:border-line">
            <h2 class="font-bold">Connected Analytics traffic and conversions</h2>
            <p class="mt-1 text-xs leading-5 text-muted dark:text-subtle">Active Analytics site connections are compared over the same plan-limited windows as this Monitor comparison. Only aggregate counts are shown.</p>
        </div>
        @forelse($trafficContexts as $trafficContext)
            <div class="border-b border-line last:border-b-0 dark:border-line">
                <div class="px-5 pt-5">
                    <p class="text-sm font-semibold">{{ $trafficContext->siteName }}</p>
                    <p class="mt-1 text-xs text-muted dark:text-subtle">Project: {{ $trafficContext->projectName }} · {{ number_format($trafficContext->windowSeconds) }}-second windows · {{ $trafficContext->deployedAt->format('Y-m-d H:i:s.u') }} UTC</p>
                </div>
                <x-monitor::ui.traffic-comparison :before="$trafficContext->before" :after="$trafficContext->after" />
            </div>
        @empty
            <p class="p-5 text-sm text-muted dark:text-subtle">No connected Analytics traffic is available for this deployment under the current workspace access and plan settings.</p>
        @endforelse
    </x-signal.ui.panel>
</div>
@endsection
