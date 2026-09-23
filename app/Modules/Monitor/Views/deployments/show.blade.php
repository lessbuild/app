@extends('monitor::layouts.app')
@section('title', 'Deployment '.$deployment->release->version)
@section('breadcrumb', 'Deployment detail')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.deployments.index', [$application, $environment]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Deployment history</a>
    <x-monitor::ui.page-header :eyebrow="$application->name.' / '.$environment->name.' · '.$deployment->release->serviceLabel()" :title="$deployment->release->version" :description="'Reported completed '.$deployment->deployed_at->utc()->format('Y-m-d H:i:s.u').' UTC'" />
    <section class="ui-panel p-5">
        <dl class="grid gap-5 text-xs sm:grid-cols-2"><div><dt class="text-muted dark:text-subtle">Recorded by</dt><dd class="mt-2 font-semibold">{{ $deployment->source === 'api' ? 'Environment API token' : ($deployment->actor?->name ?? 'Removed contributor') }}</dd></div><div><dt class="text-muted dark:text-subtle">Commit ID</dt><dd class="mt-2 break-all font-mono">{{ $deployment->commit_sha ?? 'Not reported' }}</dd></div><div><dt class="text-muted dark:text-subtle">Deployment UUID</dt><dd class="mt-2 break-all font-mono">{{ $deployment->deployment_key }}</dd></div><div><dt class="text-muted dark:text-subtle">Received (UTC)</dt><dd class="mt-2">{{ $deployment->created_at->copy()->utc()->format('Y-m-d H:i:s') }}</dd></div></dl>
        @if($note !== null)<p class="mt-5 break-words border-t border-line pt-5 text-sm dark:border-line">{{ $note }}</p>@endif
        <a href="{{ route('monitor.releases.show', ['release' => $deployment->release_id, 'environment' => $environment->id]) }}" class="mt-5 inline-block text-xs font-bold text-primary hover:underline dark:text-primary">Compare this version and inspect its events →</a>
    </section>
    <section class="ui-panel overflow-hidden">
        <form method="GET" action="{{ route('monitor.deployments.show', [$application, $environment, $deployment]) }}" class="flex flex-wrap items-end gap-4 border-b border-line p-5 dark:border-line"><x-monitor::ui.select name="window" label="Maximum window on each side" :value="$filters['window']" :options="$windowOptions" /><x-monitor::ui.button>Compare windows</x-monitor::ui.button></form>
        <div class="p-5"><h2 class="font-bold">Before and after this deployment</h2><p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">Same environment and service identity, across all explicitly reported versions. Overlapping deployments and rollbacks can affect both windows. This is a time comparison, not an attribution of failures to this deployment.</p>
        @if($comparison['seconds'] > 0)<p class="mt-2 text-xs text-muted dark:text-subtle">Equal {{ number_format($comparison['seconds']) }}-second windows: {{ $comparison['from']->format('Y-m-d H:i:s.u') }} → {{ $comparison['deployedAt']->format('Y-m-d H:i:s.u') }} → {{ $comparison['until']->format('Y-m-d H:i:s.u') }} UTC. Start inclusive, end exclusive; recent deployments use shorter windows.</p>@else<p class="mt-2 text-sm text-warning dark:text-warning">Awaiting an elapsed comparison window. Refresh after the reported deployment time.</p>@endif</div>
        <x-monitor::ui.release-comparison :left="$comparison['before']" :right="$comparison['after']" left-label="Before deployment" right-label="After deployment" />
    </section>
</div>
@endsection
