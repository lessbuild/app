@extends('monitor::layouts.app')
@section('title', 'Maintenance windows')
@section('breadcrumb', 'Maintenance')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="Incident response" title="Maintenance windows" description="Keep planned work quiet while checks continue collecting evidence.">
        @if($canManage)
            <x-slot:actions><x-monitor::ui.button :href="route('monitor.maintenance-windows.create')"><x-monitor::icon name="plus" class="h-4 w-4" />Schedule window</x-monitor::ui.button></x-slot:actions>
        @endif
    </x-monitor::ui.page-header>
    <div class="ui-alert border-primary/30 bg-primary-soft block p-4 text-xs leading-5 text-primary dark:text-primary">{{ config('app.name') }} continues to run monitors and record telemetry during a window. It only suppresses new alert and uptime incident notifications for this workspace.</div>
    <section class="ui-panel overflow-hidden"><div class="divide-y divide-line dark:divide-line">@forelse($windows as $window)<div class="flex flex-col justify-between gap-4 px-6 py-5 sm:flex-row sm:items-center"><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold">{{ $window->name }}</h2>@if($window->starts_at->isPast() && $window->ends_at->isFuture())<x-monitor::ui.badge tone="amber">Active now</x-monitor::ui.badge>@elseif($window->starts_at->isFuture())<x-monitor::ui.badge tone="sky">Scheduled</x-monitor::ui.badge>@else<x-monitor::ui.badge tone="slate">Completed</x-monitor::ui.badge>@endif</div><p class="mt-2 text-xs text-muted dark:text-subtle">{{ $window->starts_at->format('Y-m-d H:i') }} → {{ $window->ends_at->format('Y-m-d H:i') }} UTC</p>@if($window->reason)<p class="mt-1 text-xs text-muted dark:text-subtle">{{ $window->reason }}</p>@endif</div>@if($canManage)<div class="flex items-center gap-3"><a href="{{ route('monitor.maintenance-windows.edit', $window) }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">Edit</a><form method="POST" action="{{ route('monitor.maintenance-windows.destroy', $window) }}">@csrf @method('DELETE')<x-monitor::ui.button variant="secondary">Remove</x-monitor::ui.button></form></div>@endif</div>@empty<div class="px-6 py-12 text-center"><x-monitor::icon name="clock" class="mx-auto h-8 w-8 text-primary" /><h2 class="mt-4 text-lg font-bold">No planned work is muted</h2><p class="mx-auto mt-2 max-w-md text-sm text-muted dark:text-subtle">Schedule a window before a deploy, migration, or infrastructure change to keep incident notifications intentional.</p></div>@endforelse</div></section>
    {{ $windows->links() }}
</div>
@endsection
