@extends('monitor::layouts.app')

@section('title', 'Event detail')
@section('breadcrumb', 'Event detail')

@section('content')
    <div class="flex flex-col gap-6">
        <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-xs text-muted dark:text-subtle">
            <a href="{{ $backUrl }}" class="font-semibold hover:text-primary dark:hover:text-primary">{{ $backLabel }}</a>
            <x-monitor::icon name="chevron-right" class="h-3.5 w-3.5" /><span>Event detail</span>
        </nav>
        <x-monitor::ui.page-header :title="$record->name()" :description="$record->service().' · '.$record->event->environment->application->name.' / '.$record->event->environment->name">
            <x-slot:metadata>
                <x-monitor::ui.badge :tone="$record->tone()">{{ $record->isSpan ? 'Span' : 'Event' }}</x-monitor::ui.badge>
                <x-monitor::ui.badge :tone="$record->tone()">{{ ucfirst($record->event->severity) }}</x-monitor::ui.badge>
                @if($record->event->status_code !== null)
                    <x-monitor::ui.badge :tone="$record->tone()">HTTP {{ $record->event->status_code }}</x-monitor::ui.badge>
                @endif
            </x-slot:metadata>
            @if(filled($record->event->trace_id))
                <x-slot:actions><x-monitor::ui.button :href="route('monitor.traces.show', $record->event->trace_id)" variant="secondary">Open full trace</x-monitor::ui.button></x-slot:actions>
            @endif
        </x-monitor::ui.page-header>
        @if($release)<a href="{{ route('monitor.releases.show', ['release' => $release->id, 'environment' => $record->event->environment_id]) }}" class="self-start text-xs font-bold text-primary hover:underline dark:text-primary">Release {{ $release->version }} · {{ $release->serviceLabel() }} →</a>@endif
        <x-signal.ui.panel as="section" aria-labelledby="context-title" class="p-5 sm:p-6">
            <h2 id="context-title" class="text-base font-bold">Recorded context</h2>
            <dl class="mt-5 grid grid-cols-1 gap-5 text-xs sm:grid-cols-2 xl:grid-cols-3">
                @foreach([
                    'Trace ID' => $record->event->trace_id ?? 'Not reported',
                    'Span ID' => $record->event->span_id ?? 'Not reported',
                    'Parent span ID' => $record->event->parent_span_id ?? 'Not reported',
                    'Event type' => $record->event->type,
                    'Route' => $record->event->route ?? 'Not reported',
                    'Duration' => $record->durationLabel(),
                    'Received (UTC)' => $record->event->created_at?->copy()->utc()->format('Y-m-d H:i:s') ?? 'Not reported',
                    'Timestamp (UTC)' => $record->event->occurred_at->copy()->utc()->format('Y-m-d H:i:s.u'),
                    'Source timestamp (Unix ns)' => $record->event->timestamp_unix_nano ?? 'Not reported',
                    'Source end (Unix ns)' => $record->event->end_timestamp_unix_nano ?? 'Not reported',
                ] as $label => $value)
                    <div class="min-w-0"><dt class="text-muted dark:text-subtle">{{ $label }}</dt><dd class="mt-2 break-all font-mono text-ink dark:text-ink">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </x-signal.ui.panel>
        <p class="text-xs leading-5 text-muted dark:text-subtle">Stored event data is shown below with the current redaction rules applied. Redaction is best-effort; avoid sending personal data or secrets that your collection rules do not cover.</p>
        @foreach(['Attributes' => $attributesJson, 'Payload' => $payloadJson] as $label => $json)
            <x-signal.ui.card as="details" class="overflow-hidden" @if($label === 'Attributes') open @endif>
                <summary class="cursor-pointer p-5 text-sm font-bold focus-visible:outline-2 focus-visible:outline-primary">{{ $label }}</summary>
                <div class="flex flex-col gap-3 border-t border-line p-5 dark:border-line">
                    <x-monitor::ui.button type="button" variant="secondary" class="self-start" data-copy-target="event-{{ strtolower($label) }}"><span data-copy-label>Copy {{ strtolower($label) }}</span></x-monitor::ui.button>
                    <pre id="event-{{ strtolower($label) }}" class="library-code max-h-[32rem]" tabindex="0" aria-label="{{ $label }} JSON">{{ $json }}</pre>
                </div>
            </x-signal.ui.card>
        @endforeach
    </div>
@endsection
