@extends('monitor::layouts.app')
@section('title', $application->name)
@section('breadcrumb', 'Applications')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.applications.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Applications</a>
    <x-monitor::ui.page-header :title="$application->name" :description="trim($application->framework.' '.$application->framework_version)">
        <x-slot:leading><x-monitor::ui.application-mark :application="$application" class="h-12 w-12 text-sm" /></x-slot:leading>
        @if($canManage && !$application->trashed())
            <x-slot:actions><x-monitor::ui.button :href="route('monitor.applications.edit', $application)" variant="secondary">Edit application</x-monitor::ui.button></x-slot:actions>
        @endif
    </x-monitor::ui.page-header>
    @if($application->trashed())
        <section class="ui-alert ui-alert-warning flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
            <div><h2 class="font-bold text-warning dark:text-warning">This application is archived</h2><p class="mt-2 text-sm text-warning dark:text-warning">Its telemetry is preserved. Restoring it does not reactivate revoked tokens.</p></div>
            @if($canManage)<form method="POST" action="{{ route('monitor.applications.restore', $application) }}">@csrf<x-monitor::ui.button>Restore application</x-monitor::ui.button></form>@endif
        </section>
    @endif
    <section class="ui-panel overflow-hidden">
        <div class="border-b border-line px-6 py-4 dark:border-line"><h2 class="font-bold">Environments</h2><p class="mt-1 text-xs text-muted dark:text-subtle">Separate credentials and telemetry for production, staging, and development.</p></div>
        <div class="divide-y divide-line dark:divide-line">
            @forelse($environments as $environment)
                <div class="flex flex-col justify-between gap-4 px-6 py-5 sm:flex-row sm:items-center">
                    <div><h3 class="text-sm font-bold">@unless($application->trashed())<a href="{{ route('monitor.environments.show', [$application, $environment]) }}" class="hover:text-primary dark:hover:text-primary">{{ $environment->name }}</a>@else{{ $environment->name }}@endunless</h3><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $environment->slug }} · {{ number_format($environment->event_count) }} events · {{ $environment->active_token_count }} active keys</p></div>
                    <div class="flex items-center gap-3">
                        <x-monitor::ui.badge :tone="$environment->trashed() || $environment->status === 'paused' ? 'amber' : ($environment->last_seen_at ? 'green' : 'slate')">{{ $environment->trashed() ? 'Archived' : ($environment->status === 'paused' ? 'Paused' : ($environment->last_seen_at ? 'Events received' : 'Awaiting events')) }}</x-monitor::ui.badge>
                        @unless($application->trashed())<a href="{{ route('monitor.environments.show', [$application, $environment]) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">{{ $canManage ? 'Setup & keys' : 'View details' }} →</a>@endunless
                    </div>
                </div>
            @empty
                <p class="px-6 py-8 text-sm text-muted dark:text-subtle">No environments yet. Add one below to begin collecting telemetry.</p>
            @endforelse
        </div>
    </section>
    @if($canManage && !$application->trashed())
        <div class="grid gap-6 xl:grid-cols-2">
            <section class="ui-panel p-6">
                <h2 class="mb-5 font-bold">Add an environment</h2>
                <form method="POST" action="{{ route('monitor.environments.store', $application) }}" class="space-y-4">
                    @csrf
                    <x-monitor::ui.input name="name" label="Environment name" placeholder="Staging" maxlength="120" required />
                    <x-monitor::ui.input name="slug" label="Identifier" placeholder="staging" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="80" required />
                    <x-monitor::ui.select id="environment-status" name="status" label="Ingestion" value="active" :options="['active' => 'Active', 'paused' => 'Paused']" />
                    <x-monitor::ui.button>Create environment</x-monitor::ui.button>
                </form>
            </section>
            <section class="ui-panel shadow-none border-danger bg-surface p-6 dark:border-danger dark:bg-surface">
                <h2 class="font-bold text-danger dark:text-danger">Archive application</h2>
                <p class="mt-2 text-sm leading-6 text-muted dark:text-subtle">All ingestion keys will be revoked immediately. Telemetry will be retained and the application can be restored.</p>
                <form method="POST" action="{{ route('monitor.applications.destroy', $application) }}" class="mt-5 space-y-4">
                    @csrf @method('DELETE')
                    <x-monitor::ui.input name="confirmation" :label="'Type '.$application->name.' to confirm'" autocomplete="off" required />
                    <x-monitor::ui.button variant="secondary">Archive application</x-monitor::ui.button>
                </form>
            </section>
        </div>
    @endif
</div>
@endsection
