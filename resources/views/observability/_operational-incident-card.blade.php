@php
    $incidentTone = $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED
        ? 'success'
        : ($incident->severity === 'critical' ? 'danger' : 'warning');
    $noteDialogId = 'operational-incident-note-'.$incident->id;
    $noteDialogKey = 'incident-note-'.$incident->id;
    $noteDialogHasErrors = old('_operational_incident_form') === 'note'
        && (string) old('_operational_incident_id') === (string) $incident->id
        && $errors->has('message');
    $noteDialogOpen = request()->query('dialog') === $noteDialogKey || $noteDialogHasErrors;
    $noteDialogUrl = route('observability.index', ['dialog' => $noteDialogKey]);
    $timelineDialogId = 'operational-incident-timeline-dialog';
    $timelineDialogKey = 'operational-incident-'.$incident->id;
    $timelineDialogUrl = route('observability.index', ['dialog' => $timelineDialogKey]);
    $timelineContentUrl = route('observability.index', [
        'fragment' => 'operational-incident',
        'incident_id' => $incident->id,
    ]);
@endphp

<article class="ui-panel bg-surface p-4" data-observability-incident>
    <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge :tone="$incidentTone">{{ str($incident->status)->headline() }}</x-ui.badge>
                <span class="text-xs text-muted">{{ str($incident->severity)->headline() }} · {{ str($incident->category)->headline() }} #{{ $incident->resource_id }} · {{ trans_choice(':count occurrence|:count occurrences', $incident->occurrences, ['count' => $incident->occurrences]) }}</span>
            </div>
            <h3 class="mt-2 font-extrabold text-ink">{{ $incident->title }}</h3>
            <p class="mt-2 text-xs text-muted">{{ __('Detected :time · Owner: :owner', ['time' => $incident->detected_at->diffForHumans(), 'owner' => $incident->assignee?->name ?? __('Unassigned')]) }}</p>
        </div>
        @if ($canOperate && $incident->status !== \App\Models\OperationalIncident::STATUS_RESOLVED)
            <form method="POST" action="{{ route('observability.operational-incidents.acknowledge', $incident) }}" class="shrink-0">
                @csrf
                <x-ui.button type="submit" variant="secondary">{{ __('Acknowledge') }}</x-ui.button>
            </form>
        @endif
    </div>

    <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
        <x-ui.button
            :href="$timelineDialogUrl"
            data-modal-trigger="{{ $timelineDialogId }}"
            data-modal-content-url="{{ $timelineContentUrl }}"
            data-modal-history-url="{{ $timelineDialogUrl }}"
            aria-controls="{{ $timelineDialogId }}"
            aria-expanded="{{ request()->query('dialog') === $timelineDialogKey ? 'true' : 'false' }}"
            variant="secondary"
        >
            {{ __('Timeline and response') }}
        </x-ui.button>
    </div>

    @if ($canOperate && $incident->status !== \App\Models\OperationalIncident::STATUS_RESOLVED)
        <details class="mt-4 rounded-card border border-line bg-surface-muted p-3" @if ($openDetails || ($errors->any() && ! $noteDialogHasErrors)) open @endif>
            <summary class="cursor-pointer text-xs font-bold text-ink">{{ __('Response actions') }}</summary>
            <div class="mt-3">
            <div class="mt-5 grid gap-4 border-t border-line pt-4 lg:grid-cols-3">
                <form method="POST" action="{{ route('observability.operational-incidents.assign', $incident) }}" class="flex items-end gap-2">
                    @csrf
                    @method('PATCH')
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">{{ __('Assignee') }}</span>
                        <select name="assigned_to" class="ui-input">
                            <option value="">{{ __('Unassigned') }}</option>
                            @foreach ($incidentResponders as $responder)
                                <option value="{{ $responder->id }}" @selected($incident->assigned_to === $responder->id)>{{ $responder->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-ui.button type="submit" variant="secondary">{{ __('Assign') }}</x-ui.button>
                </form>
                <div>
                    <x-ui.button
                        :href="$noteDialogUrl"
                        data-modal-trigger="{{ $noteDialogId }}"
                        aria-controls="{{ $noteDialogId }}"
                        aria-expanded="{{ $noteDialogOpen ? 'true' : 'false' }}"
                        variant="secondary"
                    >
                        {{ __('Add investigation note') }}
                    </x-ui.button>
                    <x-scenes.observability.incident-note-dialog :incident="$incident" :open="$noteDialogOpen" />
                </div>
                <form method="POST" action="{{ route('observability.operational-incidents.resolve', $incident) }}" class="flex items-end gap-2">
                    @csrf
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">{{ __('Resolution and evidence') }}</span>
                        <input name="resolution" maxlength="5000" required class="ui-input" placeholder="{{ __('Resolution and evidence') }}">
                    </label>
                    <x-ui.button type="submit" variant="primary">{{ __('Resolve') }}</x-ui.button>
                </form>
            </div>
            </div>
        </details>
    @endif
</article>
