@php
    $incidentTone = $incident->status === \App\Modules\Deployer\Models\OperationalIncident::STATUS_RESOLVED
        ? 'success'
        : ($incident->severity === 'critical' ? 'danger' : 'warning');
@endphp

<div data-operational-incident-content class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Operational incident') }}</p>
            <h3 class="mt-1 text-xl font-extrabold text-ink">{{ $incident->title }}</h3>
            <p class="mt-1 text-sm text-muted">
                {{ str($incident->severity)->headline() }} · {{ str($incident->category)->headline() }} #{{ $incident->resource_id }} · {{ trans_choice(':count occurrence|:count occurrences', $incident->occurrences, ['count' => $incident->occurrences]) }}
            </p>
        </div>
        <x-signal.ui.badge :tone="$incidentTone">{{ str($incident->status)->headline() }}</x-signal.ui.badge>
    </div>

    <x-signal.ui.panel as="p" class="bg-surface-muted p-4 text-sm leading-6 text-ink">{{ $incident->summary }}</x-signal.ui.panel>

    <section aria-labelledby="operational-incident-timeline-heading">
        <h4 id="operational-incident-timeline-heading" class="ui-eyebrow">{{ __('Timeline') }}</h4>
        <ol class="ui-timeline mt-3 space-y-3">
            @forelse ($incident->events->sortByDesc('occurred_at') as $event)
                <li>
                    <p class="text-xs font-bold text-ink">{{ str($event->type)->headline() }} · {{ $event->occurred_at->utc()->format('M j H:i').' UTC' }} @if ($event->actor)· {{ $event->actor->name }}@endif</p>
                    <p class="text-sm text-muted">{{ $event->message }}</p>
                </li>
            @empty
                <li class="text-sm text-muted">{{ __('No incident events were recorded.') }}</li>
            @endforelse
        </ol>
    </section>

    @if ($incident->resolution)
        <x-signal.ui.panel as="section" class="ui-panel bg-surface-muted p-4" aria-labelledby="operational-incident-resolution-heading">
            <h4 id="operational-incident-resolution-heading" class="ui-eyebrow">{{ __('Resolution') }}</h4>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-ink">{{ $incident->resolution }}</p>
        </x-signal.ui.panel>
    @endif
</div>
