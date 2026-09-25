@extends('monitor::layouts.app')
@section('title', 'Monitors')
@section('breadcrumb', 'Monitors')
@section('content')
<x-monitor::ui.page-header eyebrow="Reliability" title="Monitors" description="HTTP, DNS, TLS, TCP ports, scheduled jobs and queue / worker health for any stack. Queue and job monitors accept signals from your own collectors.">
    <x-slot:actions>
        @if($canCreate)<x-monitor::ui.button :href="route('monitor.monitors.create')">Create monitor</x-monitor::ui.button>@endif
    </x-slot:actions>
</x-monitor::ui.page-header>
<form method="GET" action="{{ route('monitor.monitors.index') }}" class="flex flex-wrap items-end gap-3">
    <x-monitor::ui.select name="state" label="Show monitors" :value="$state" :options="['all' => 'All current', 'enabled' => 'Enabled', 'paused' => 'Paused', 'archived' => 'Archived']" />
    <x-monitor::ui.select name="check_type" label="Check type" :value="$checkType" :options="['' => 'All types'] + \App\Modules\Monitor\Http\Requests\SaveMonitorRequest::TYPES" />
    <x-monitor::ui.button variant="secondary">Filter</x-monitor::ui.button>
</form>
<x-signal.ui.card as="div" class="overflow-x-auto">
    <x-monitor::ui.table caption="Monitors and latest results" :framed="false">
        <x-slot:head><tr><th scope="col">Monitor / environment</th><th scope="col">Latest result</th><th scope="col">Interval</th><th scope="col">Last checked (UTC)</th></tr></x-slot:head>
            @forelse($monitors as $monitor)
            <tr>
                <td class="max-w-sm"><a href="{{ route('monitor.monitors.show', $monitor) }}" class="break-words font-bold text-primary hover:underline dark:text-primary">{{ $monitor->name }}</a><p class="mt-1 text-xs text-muted dark:text-subtle">{{ $monitor->environment->application->name }} / {{ $monitor->environment->name }}</p></td>
                <td><x-monitor::ui.badge :tone="$monitor->healthLabel() === 'Up' ? 'green' : ($monitor->healthLabel() === 'Down' ? 'red' : 'slate')">{{ $monitor->healthLabel() }}</x-monitor::ui.badge></td>
                <td>{{ in_array($monitor->type, ['heartbeat', 'queue']) ? $monitor->targetLabel() : $monitor->interval_minutes.' min' }}<p class="mt-1 text-xs text-muted dark:text-subtle">{{ $monitor->typeLabel() }}</p></td>
                <td class="whitespace-nowrap">{{ $monitor->checked_at?->format('Y-m-d H:i:s') ?? 'No observation yet' }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="py-14 text-center text-muted dark:text-subtle">No monitors in this view. Add an HTTP, DNS, TLS or TCP check, job heartbeat or queue monitor.</td></tr>
            @endforelse
    </x-monitor::ui.table>
</x-signal.ui.card>
{{ $monitors->links() }}
<p class="text-xs leading-5 text-muted dark:text-subtle">Checks currently run from one location. An unknown or stale result is not evidence that an endpoint is up or down. Private-network targets and automatic redirects are blocked.</p>
@endsection
