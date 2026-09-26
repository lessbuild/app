@extends('monitor::layouts.app')
@section('title', 'Applications')
@section('breadcrumb', 'Applications')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="YOUR STACK" title="Applications" description="Organise your services and keep each environment separate.">
        <x-slot:actions>@if($canCreate)<x-monitor::ui.button :href="route('monitor.applications.create')"><x-monitor::icon name="plus" class="h-4 w-4" />New application</x-monitor::ui.button>@endif</x-slot:actions>
    </x-monitor::ui.page-header>
    @if($canManage && $applicationCapacity['at_limit'])
        @if(! $applicationCapacity['plan_available'] || ! $applicationCapacity['limit_configured'])
            <x-signal.ui.alert as="p" tone="info" class="border-info/30 bg-info-soft block p-4 text-xs leading-5 text-info dark:text-info">Monitor could not verify this workspace’s application allowance. Reconcile its Core subscription before connecting another service.</x-signal.ui.alert>
        @else
            <x-signal.ui.alert as="p" tone="warning" class="block p-4 text-xs leading-5 text-warning dark:text-warning">Your current plan has reached its {{ $applicationCapacity['limit'] }}-application allowance. Upgrade the workspace plan or archive an application before connecting another service.</x-signal.ui.alert>
        @endif
    @endif
    <nav class="flex gap-2" aria-label="Application status">
        <a href="{{ route('monitor.applications.index') }}" @class(['rounded-control px-4 py-2 text-xs font-bold', 'bg-emphasis text-emphasis-ink dark:bg-surface dark:text-emphasis-ink' => !$archived, 'text-muted hover:bg-line dark:hover:bg-surface-muted' => $archived])>Active</a>
        <a href="{{ route('monitor.applications.index', ['status' => 'archived']) }}" @class(['rounded-control px-4 py-2 text-xs font-bold', 'bg-emphasis text-emphasis-ink dark:bg-surface dark:text-emphasis-ink' => $archived, 'text-muted hover:bg-line dark:hover:bg-surface-muted' => !$archived])>Archived</a>
    </nav>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse($applications as $application)
            <x-signal.ui.card as="a" href="{{ route('monitor.applications.show', $application) }}" class="group p-5 transition hover:border-primary dark:hover:border-primary">
                <div class="flex items-center gap-3"><x-monitor::ui.application-mark :application="$application" /><div class="min-w-0 flex-1"><h2 class="truncate text-sm font-bold group-hover:text-primary dark:group-hover:text-primary">{{ $application->name }}</h2><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $application->framework }} {{ $application->framework_version }}</p></div><x-monitor::icon name="chevron-right" class="h-4 w-4 text-subtle" /></div>
                <div class="mt-6 flex items-center justify-between border-t border-line pt-4 text-xs text-muted dark:border-line dark:text-subtle"><span>{{ $application->environments_count }} environments</span><span>{{ number_format($application->environments_sum_event_count ?? 0) }} events</span></div>
                @if($archived)<p class="mt-3 text-xs text-warning dark:text-warning">{{ $application->trashed() ? 'Archived' : 'Restoration pending' }} · telemetry preserved</p>@endif
            </x-signal.ui.card>
        @empty
            <x-monitor::ui.empty-state icon="server" class="md:col-span-2 xl:col-span-3" :title="$archived ? 'No archived applications' : 'Bring your first application into view'" :description="$archived ? 'Archived applications remain recoverable here.' : 'Create an application, copy its private token, and send a first event from any language.'">
                <x-slot:action>@if($canCreate && ! $archived)<a href="{{ route('monitor.applications.create') }}" class="mt-3 text-sm font-bold text-primary hover:underline dark:text-primary">Connect an application →</a>@endif</x-slot:action>
            </x-monitor::ui.empty-state>
        @endforelse
    </div>
    {{ $applications->links() }}
</div>
@endsection
