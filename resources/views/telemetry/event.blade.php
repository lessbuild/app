@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$record->name()" :description="__(ucfirst($event->type)).' · '.$event->environment->name.' · '.$event->occurred_at->format('Y-m-d H:i:s.u').' UTC'">
    <div class="grid gap-4 sm:grid-cols-4">
        <x-signal.ui.stat :label="__('Severity')" :value="__(ucfirst($event->severity))" />
        <x-signal.ui.stat :label="__('Duration')" :value="$record->durationLabel()" />
        <x-signal.ui.stat :label="__('Status')" :value="$event->status_code ?? '—'" />
        <x-signal.ui.stat :label="__('Service')" :value="$event->service ?? '—'" />
    </div>
    <x-signal.ui.card class="grid gap-2 p-5 text-sm">
        @if ($event->route)<p><span class="text-muted">{{ __('Route') }}:</span> <span class="break-all">{{ $event->route }}</span></p>@endif
        @if ($release)<p><span class="text-muted">{{ __('Release') }}:</span> <a class="text-primary hover:underline" href="{{ route('monitoring.releases.show', [$project, $release->id]) }}">{{ $release->version }}</a></p>@endif
        @if ($event->trace_id)<p><span class="text-muted">{{ __('Trace') }}:</span> <a class="break-all text-primary hover:underline" href="{{ route('monitoring.traces.show', [$project, $event->trace_id]) }}">{{ $event->trace_id }}</a></p>@endif
        @if ($event->issue_id)<p><a class="text-primary hover:underline" href="{{ route('monitoring.issues.show', [$project, $event->issue_id]) }}">{{ __('View the issue') }}</a></p>@endif
    </x-signal.ui.card>
    <x-signal.ui.card class="p-5">
        <h2 class="text-sm font-bold text-ink">{{ __('Attributes') }}</h2>
        <x-signal.ui.code-block :code="$attributesJson" class="mt-3 max-h-96 overflow-auto text-xs" />
    </x-signal.ui.card>
    <x-signal.ui.card class="p-5">
        <h2 class="text-sm font-bold text-ink">{{ __('Payload') }}</h2>
        <p class="mt-1 text-xs text-muted">{{ __('Sensitive values are redacted when stored and again when shown.') }}</p>
        <x-signal.ui.code-block :code="$payloadJson" class="mt-3 max-h-[32rem] overflow-auto text-xs" />
    </x-signal.ui.card>
</x-signal.layouts.project>
