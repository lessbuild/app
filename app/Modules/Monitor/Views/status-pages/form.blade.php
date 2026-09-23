@extends('monitor::layouts.app')
@section('title', $statusPage->exists ? 'Edit status page' : 'Create status page')
@section('breadcrumb', 'Status pages')
@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <x-monitor::ui.page-header eyebrow="Public surface" :title="$statusPage->exists ? 'Tune your status page.' : 'Make incidents easier to understand.'" description="Select only the monitors you are comfortable sharing. Private target URLs and credentials never appear here." />
    <form method="POST" action="{{ $statusPage->exists ? route('monitor.status-pages.update', $statusPage) : route('monitor.status-pages.store') }}" class="space-y-6">
        @csrf
        @if($statusPage->exists) @method('PATCH') @endif
        <section class="ui-panel space-y-5 p-6">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-monitor::ui.input name="name" label="Page name" :value="$statusPage->name" maxlength="120" required />
                <x-monitor::ui.input name="slug" label="Public URL slug" :value="$statusPage->slug" maxlength="100" placeholder="acme-status" />
            </div>
            <x-monitor::ui.textarea name="description" label="Customer-facing description" :value="$statusPage->description" maxlength="1000" />
            <input type="hidden" name="published" value="0">
            <x-monitor::ui.choice id="published" name="published" :checked="old('published', session()->hasOldInput() ? false : $statusPage->published)" label="Publish this page" description="Anyone with the public URL can see component names, high-level health, and active monitor incidents." card />
        </section>
        <fieldset class="ui-card space-y-4 p-6" aria-describedby="components-help{{ $errors->has('monitor_ids') ? ' components-error' : '' }}">
            <legend class="px-2 font-bold">Public components</legend>
            <p id="components-help" class="ui-help">Order follows the list below. Keep sensitive internal monitors out of customer-facing pages.</p>
            @php
                $oldMonitorIds = old('monitor_ids', session()->hasOldInput() ? [] : $selectedMonitorIds);
                $selected = collect(is_array($oldMonitorIds) ? $oldMonitorIds : [])
                    ->filter(fn ($id): bool => is_scalar($id))
                    ->map(fn ($id): int => (int) $id)->all();
            @endphp
            <div class="space-y-3">
                @forelse($monitors as $monitor)
                    <x-monitor::ui.choice :id="'public-monitor-'.$monitor->id" name="monitor_ids[]" :value="$monitor->id" :checked="in_array($monitor->id, $selected, true)" :label="$monitor->name" :description="$monitor->typeLabel().' · '.$monitor->environment->application->name.' / '.$monitor->environment->name" card>
                        <x-monitor::ui.badge :tone="$monitor->healthLabel() === 'Up' ? 'green' : ($monitor->healthLabel() === 'Down' ? 'red' : 'amber')">{{ $monitor->healthLabel() }}</x-monitor::ui.badge>
                    </x-monitor::ui.choice>
                @empty
                    <p class="py-4 text-sm text-muted">Create an uptime, heartbeat, queue, DNS, TLS, or TCP monitor first.</p>
                @endforelse
            </div>
            @error('monitor_ids')<p id="components-error" class="ui-error">{{ $message }}</p>@enderror
        </fieldset>
        <div class="flex flex-wrap items-center justify-between gap-3"><x-monitor::ui.button :href="route('monitor.status-pages.index')" variant="quiet">Cancel</x-monitor::ui.button><x-monitor::ui.button>{{ $statusPage->exists ? 'Save status page' : 'Create status page' }}</x-monitor::ui.button></div>
    </form>
    @if($statusPage->exists)
        <section class="ui-alert ui-alert-danger block p-5"><h2 class="text-sm font-bold text-danger dark:text-danger">Delete this status page</h2><p class="mt-1 text-xs leading-5 text-danger dark:text-danger">The public URL will stop working immediately. Monitors and their history are not affected.</p><form method="POST" action="{{ route('monitor.status-pages.destroy', $statusPage) }}" class="mt-4">@csrf @method('DELETE')<x-monitor::ui.button variant="secondary" class="border-danger text-danger dark:border-danger dark:text-danger">Delete page</x-monitor::ui.button></form></section>
    @endif
</div>
@endsection
