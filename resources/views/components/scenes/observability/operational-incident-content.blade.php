@php
    $incidentTone = $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED
        ? 'success'
        : ($incident->severity === 'critical' ? 'danger' : 'warning');
@endphp

<div data-operational-incident-content class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Operational incident') }}</p>
            <h3 class="mt-1 text-xl font-black text-primary">{{ $incident->title }}</h3>
            <p class="mt-1 text-sm text-secondary">
                {{ str($incident->severity)->headline() }} · {{ str($incident->category)->headline() }} #{{ $incident->resource_id }} · {{ trans_choice(':count occurrence|:count occurrences', $incident->occurrences, ['count' => $incident->occurrences]) }}
            </p>
        </div>
        <x-ui.badge :tone="$incidentTone">{{ str($incident->status)->headline() }}</x-ui.badge>
    </div>

    <p class="rounded-xl border border-primary bg-secondary p-4 text-sm leading-6 text-primary">{{ $incident->summary }}</p>

    <section aria-labelledby="operational-incident-timeline-heading">
        <h4 id="operational-incident-timeline-heading" class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Timeline') }}</h4>
        <ol class="mt-3 space-y-3 border-l border-primary pl-4">
            @forelse ($incident->events->sortByDesc('occurred_at') as $event)
                <li>
                    <p class="text-xs font-bold text-primary">{{ str($event->type)->headline() }} · {{ $event->occurred_at->utc()->format('M j H:i').' UTC' }} @if ($event->actor)· {{ $event->actor->name }}@endif</p>
                    <p class="text-sm text-secondary">{{ $event->message }}</p>
                </li>
            @empty
                <li class="text-sm text-secondary">{{ __('No incident events were recorded.') }}</li>
            @endforelse
        </ol>
    </section>

    @if ($incident->resolution)
        <section class="ui-card ui-card--muted p-4" aria-labelledby="operational-incident-resolution-heading">
            <h4 id="operational-incident-resolution-heading" class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Resolution') }}</h4>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-primary">{{ $incident->resolution }}</p>
        </section>
    @endif
</div>
