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
@endphp

<article class="rounded-xl border border-primary bg-secondary p-4">
    <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge :tone="$incidentTone">{{ str($incident->status)->headline() }}</x-ui.badge>
                <span class="text-xs text-secondary">{{ str($incident->severity)->headline() }} · {{ str($incident->category)->headline() }} #{{ $incident->resource_id }} · {{ trans_choice(':count occurrence|:count occurrences', $incident->occurrences, ['count' => $incident->occurrences]) }}</span>
            </div>
            <h3 class="mt-2 font-black text-primary">{{ $incident->title }}</h3>
            <p class="mt-2 text-xs text-secondary">{{ __('Detected :time · Owner: :owner', ['time' => $incident->detected_at->diffForHumans(), 'owner' => $incident->assignee?->name ?? __('Unassigned')]) }}</p>
        </div>
        @if ($canOperate && $incident->status !== \App\Models\OperationalIncident::STATUS_RESOLVED)
            <form method="POST" action="{{ route('observability.operational-incidents.acknowledge', $incident) }}" class="shrink-0">
                @csrf
                <x-ui.button type="submit" variant="secondary">{{ __('Acknowledge') }}</x-ui.button>
            </form>
        @endif
    </div>

    <details class="mt-4 rounded-lg border border-primary bg-primary p-3" @if ($openDetails || ($errors->any() && ! $noteDialogHasErrors)) open @endif>
        <summary class="cursor-pointer text-xs font-bold text-ternary">{{ __('Timeline and response') }}</summary>
        <p class="mt-3 text-sm text-secondary">{{ $incident->summary }}</p>
        <ol class="mt-3 space-y-3 border-l border-primary pl-4">
            @foreach ($incident->events->sortByDesc('occurred_at') as $event)
                <li>
                    <p class="text-xs font-bold text-primary">{{ str($event->type)->headline() }} · {{ $event->occurred_at->utc()->format('M j H:i').' UTC' }} @if ($event->actor)· {{ $event->actor->name }}@endif</p>
                    <p class="text-sm text-secondary">{{ $event->message }}</p>
                </li>
            @endforeach
        </ol>

        @if ($canOperate && $incident->status !== \App\Models\OperationalIncident::STATUS_RESOLVED)
            <div class="mt-5 grid gap-4 border-t border-primary pt-4 lg:grid-cols-3">
                <form method="POST" action="{{ route('observability.operational-incidents.assign', $incident) }}" class="flex items-end gap-2">
                    @csrf
                    @method('PATCH')
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">{{ __('Assignee') }}</span>
                        <select name="assigned_to" class="input secondary w-full rounded-md">
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
                    <x-dialogs.modal
                        :id="$noteDialogId"
                        :title="__('Add investigation note')"
                        :description="__('Record bounded evidence in the incident timeline without changing its status.')"
                        :open="$noteDialogOpen"
                    >
                        <form method="POST" action="{{ route('observability.operational-incidents.notes.store', $incident) }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="_operational_incident_form" value="note">
                            <input type="hidden" name="_operational_incident_id" value="{{ $incident->id }}">
                            <label for="{{ $noteDialogId }}-message" class="block text-xs font-semibold uppercase text-secondary">{{ __('Investigation note') }}</label>
                            <textarea id="{{ $noteDialogId }}-message" name="message" rows="5" maxlength="5000" required class="input secondary w-full rounded-md" placeholder="{{ __('Investigation note') }}">{{ old('message') }}</textarea>
                            <x-forms.errors name="message" />
                            <x-ui.button type="submit" variant="primary">{{ __('Add note') }}</x-ui.button>
                        </form>
                    </x-dialogs.modal>
                </div>
                <form method="POST" action="{{ route('observability.operational-incidents.resolve', $incident) }}" class="flex items-end gap-2">
                    @csrf
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">{{ __('Resolution and evidence') }}</span>
                        <input name="resolution" maxlength="5000" required class="input secondary w-full rounded-md" placeholder="{{ __('Resolution and evidence') }}">
                    </label>
                    <x-ui.button type="submit" variant="primary">{{ __('Resolve') }}</x-ui.button>
                </form>
            </div>
        @endif

        @if ($incident->resolution)
            <div class="ui-card ui-card--muted mt-4 p-3">
                <p class="text-xs font-bold uppercase text-secondary">{{ __('Resolution') }}</p>
                <p class="mt-1 text-sm text-primary">{{ $incident->resolution }}</p>
            </div>
        @endif
    </details>
</article>
