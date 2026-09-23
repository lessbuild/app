@extends('monitor::layouts.app')
@section('title', 'Deployments · '.$environment->name)
@section('breadcrumb', 'Deployments')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.environments.show', [$application, $environment]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← {{ $application->name }} / {{ $environment->name }}</a>
    <x-monitor::ui.page-header title="Deployments" :description="'Recorded completed deployments, newest source time first. '.config('app.name').' does not deploy code.'">
        @can('create', [\App\Modules\Monitor\Models\Deployment::class, $environment])
            <x-slot:actions><x-monitor::ui.button :href="route('monitor.deployments.create', [$application, $environment])">Record deployment</x-monitor::ui.button></x-slot:actions>
        @endcan
    </x-monitor::ui.page-header>
    <section class="ui-panel overflow-hidden">
        <p class="border-b border-line p-5 text-xs text-muted dark:border-line dark:text-subtle">{{ number_format($deployments->total()) }} completed deployment records · manual refresh</p>
        <div class="divide-y divide-line dark:divide-line">@forelse($deployments as $deployment)<a href="{{ route('monitor.deployments.show', [$application, $environment, $deployment]) }}" class="flex flex-col justify-between gap-3 px-5 py-4 hover:bg-surface-muted sm:flex-row dark:hover:bg-surface-muted"><div class="min-w-0"><p class="truncate text-sm font-bold">{{ $deployment->release->version }}</p><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $deployment->release->serviceLabel() }}</p></div><p class="text-xs text-muted dark:text-subtle">{{ $deployment->deployed_at->utc()->format('Y-m-d H:i:s') }} UTC · {{ match ($deployment->source) { 'api' => 'API', 'integration' => 'Deployer integration', default => 'Manual' } }}</p></a>@empty<p class="p-8 text-sm text-muted dark:text-subtle">No deployments recorded yet.</p>@endforelse</div>
        @if($deployments->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $deployments->links() }}</div>@endif
    </section>
    @can('create', [\App\Modules\Monitor\Models\Deployment::class, $environment])
    <section class="ui-panel p-5"><h2 class="font-bold">Record from your CI pipeline</h2><p class="mt-2 text-xs leading-5 text-muted dark:text-subtle">POST JSON to <code class="wrap-anywhere">{{ route('monitor.api.deployments.store') }}</code> with this environment’s active ingestion token as a Bearer token. Required fields: deployment_id (UUID) and version. Optional: service, service_namespace, commit_sha, note and deployed_at (ISO 8601 with timezone). Match the service labels used by your telemetry.</p><pre class="library-code mt-4"><code>{
  "deployment_id": "85e466e4-9c53-4f2c-a9c8-d987bbfce552",
  "version": "build-42",
  "service": "api",
  "service_namespace": "shop"
}</code></pre><p class="mt-3 text-xs leading-5 text-muted dark:text-subtle">Generate a new UUID per completed deployment, including rollbacks. Reuse the UUID and unchanged details on retry: 201 means created, 200 means replay, 409 means conflicting details. Omitting deployed_at records server time on the first request. Use HTTPS before transmitting production tokens.</p></section>
    @endcan
</div>
@endsection
