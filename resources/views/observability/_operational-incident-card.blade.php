@php
    $incidentTone = $incident->status === \App\Models\OperationalIncident::STATUS_RESOLVED
        ? 'success'
        : ($incident->severity === 'critical' ? 'danger' : 'warning');
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

    <details class="mt-4 rounded-lg border border-primary bg-primary p-3" @if ($openDetails || $errors->any()) open @endif>
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
                <form method="POST" action="{{ route('observability.operational-incidents.notes.store', $incident) }}" class="flex items-end gap-2">
                    @csrf
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">{{ __('Investigation note') }}</span>
                        <input name="message" maxlength="5000" required class="input secondary w-full rounded-md" placeholder="{{ __('Investigation note') }}">
                    </label>
                    <x-ui.button type="submit" variant="secondary">{{ __('Add note') }}</x-ui.button>
                </form>
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
