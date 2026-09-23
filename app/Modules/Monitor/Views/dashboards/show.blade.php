@extends('monitor::layouts.app')
@section('title', $dashboard->name)
@section('breadcrumb', 'Dashboards')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.dashboards.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Dashboards</a>
    <x-monitor::ui.page-header :title="$dashboard->name" :description="($dashboard->description ?: 'Shared workspace observability view').' · '.$report['rangeLabel'].' · refresh to update'">
        @if($canManage)
            <x-slot:actions><x-monitor::ui.button :href="route('monitor.dashboards.edit', $dashboard)" variant="secondary"><x-monitor::icon name="settings" class="h-4 w-4" />Edit dashboard</x-monitor::ui.button></x-slot:actions>
        @endif
    </x-monitor::ui.page-header>
    <div class="grid gap-6 xl:grid-cols-2">
        @foreach($report['widgets'] as $widget)
            @include('dashboards.widgets.'.$widget['type'], ['data' => $widget['data']])
        @endforeach
    </div>
</div>
@endsection
