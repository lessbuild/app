@extends('monitor::layouts.app')
@section('title', 'Dashboards')
@section('breadcrumb', 'Dashboards')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="TEAM OBSERVABILITY" title="Saved dashboards" description="Give every team a focused view of the signals they own.">
        <x-slot:actions>@if($canManage && ! $capacity['at_limit'])<x-monitor::ui.button :href="route('monitor.dashboards.create')"><x-monitor::icon name="plus" class="h-4 w-4" />Create dashboard</x-monitor::ui.button>@endif</x-slot:actions>
    </x-monitor::ui.page-header>
    <x-signal.ui.alert as="div" tone="info" class="border-primary/30 bg-primary-soft flex flex-wrap items-center justify-between gap-3 p-4 text-xs text-primary dark:text-primary">
        <span>{{ ! $capacity['plan_available'] || ! $capacity['limit_configured'] ? 'Saved dashboards: '.number_format($capacity['used']).' · plan allowance unverified' : 'Saved dashboards on your plan: '.number_format($capacity['used']).($capacity['limit'] === null ? ' · unlimited' : ' / '.number_format($capacity['limit'])) }}</span>
        @if($capacity['at_limit'])<a href="{{ route('monitor.settings.billing') }}" class="font-bold underline">Review plans</a>@endif
    </x-signal.ui.alert>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse($dashboards as $dashboard)
            <x-signal.ui.card as="article" class="flex flex-col p-5">
                <div class="flex items-start justify-between gap-3"><div><h2 class="text-sm font-bold">{{ $dashboard->name }}</h2><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $dashboard->widgets_count }} widgets · {{ \App\Modules\Monitor\Models\Dashboard::RANGES[$dashboard->range] ?? 'Selected window' }}</p></div><x-monitor::ui.badge tone="violet">Team view</x-monitor::ui.badge></div>
                @if($dashboard->description)<p class="mt-4 line-clamp-3 text-xs leading-5 text-muted dark:text-subtle">{{ $dashboard->description }}</p>@endif
                <div class="mt-auto flex flex-wrap items-center gap-3 border-t border-line pt-4 text-xs dark:border-line">
                    <a href="{{ route('monitor.dashboards.show', $dashboard) }}" class="font-bold text-primary hover:underline dark:text-primary">Open dashboard <x-monitor::icon name="arrow-up-right" class="inline h-3 w-3" /></a>
                    @if($canManage)<x-monitor::ui.button :href="route('monitor.dashboards.edit', $dashboard)" variant="quiet" size="sm">Edit</x-monitor::ui.button><form method="POST" action="{{ route('monitor.dashboards.destroy', $dashboard) }}">@csrf @method('DELETE')<x-monitor::ui.button variant="danger" size="sm">Delete</x-monitor::ui.button></form>@endif
                </div>
            </x-signal.ui.card>
        @empty
            <x-monitor::ui.empty-state icon="grid" class="md:col-span-2 xl:col-span-3" title="Build a team view" description="Combine telemetry, incidents, monitors, SLOs and applications into a saved workspace view.">
                <x-slot:action>@if($canManage && ! $capacity['at_limit'])<a href="{{ route('monitor.dashboards.create') }}" class="mt-3 text-sm font-bold text-primary hover:underline dark:text-primary">Create your first dashboard →</a>@endif</x-slot:action>
            </x-monitor::ui.empty-state>
        @endforelse
    </div>
    {{ $dashboards->links() }}
</div>
@endsection
