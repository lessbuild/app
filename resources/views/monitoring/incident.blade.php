@php($project = $overview->project)
@php($observation = \App\Data\Monitoring\MonitorObservation::class)
@php($latestReason = is_string($incident->latest_observation['reason'] ?? null) ? $incident->latest_observation['reason'] : null)

<x-signal.layouts.project :overview="$overview" :title="$incident->title" :description="'#'.$incident->id.' · '.__($incident->statusLabel())">
    <div class="grid items-start gap-6 xl:grid-cols-3">
        <div class="grid gap-6 xl:col-span-2" id="incident-live" data-live-region data-live-interval="5000">
            <x-signal.ui.card class="grid gap-3 p-5 text-sm">
                <p class="flex flex-wrap items-center gap-2">
                    <x-signal.ui.badge :tone="match ($incident->status) { 'open' => 'danger', 'acknowledged' => 'warning', default => 'neutral' }">{{ __($incident->statusLabel()) }}</x-signal.ui.badge>
                    <span class="text-muted">{{ $observation::label($latestReason) }}</span>
                </p>
                @if (is_array($incident->latest_observation['details'] ?? null))
                    @foreach (\App\Support\Monitoring\ObservationText::details($incident->latest_observation['details']) as $line)
                        <p class="text-xs text-muted">{{ $line }}</p>
                    @endforeach
                @endif
                <p class="text-muted">{{ \App\Support\Monitoring\ObservationText::configuration($incident->rule_snapshot) }}</p>
                <p class="text-muted">{{ __('Opened :opened UTC · last failure :breached UTC', ['opened' => $incident->opened_at->format('Y-m-d H:i:s'), 'breached' => $incident->last_breached_at->format('Y-m-d H:i:s')]) }}</p>
                @if ($incident->resolved_at)
                    <p>{{ __(':status at :time UTC.', ['status' => __($incident->statusLabel()), 'time' => $incident->resolved_at->format('Y-m-d H:i:s')]) }}</p>
                @endif
                @if ($incident->acknowledged_at)
                    <p>{{ __('Acknowledged by :name at :time UTC.', ['name' => $incident->acknowledgedBy->name ?? __('a former member'), 'time' => $incident->acknowledged_at->format('Y-m-d H:i:s')]) }}</p>
                @endif
                <p>{{ __('Assigned to: :name', ['name' => $incident->assignee->name ?? __('no one')]) }}</p>
                @if ($incident->monitor_id)
                    <p><a class="font-bold text-primary hover:underline" href="{{ route('monitoring.monitors.show', [$project, $incident->monitor_id]) }}">{{ __('View the monitor and its checks') }}</a></p>
                @endif
            </x-signal.ui.card>

            <section class="grid gap-3" aria-labelledby="timeline-heading">
                <h2 id="timeline-heading" class="text-sm font-bold text-ink">{{ __('Timeline') }}</h2>
                <ol class="grid gap-3">
                    @foreach ($activities as $activity)
                        <li class="rounded-panel border border-line bg-surface p-4">
                            <div class="flex flex-wrap justify-between gap-2">
                                <p class="text-sm font-semibold text-ink">{{ __($activity->label()) }}</p>
                                <time class="text-xs text-muted" datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->format('Y-m-d H:i:s') }} UTC</time>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $activity->actor->name ?? __('Automatic') }}</p>
                            @if ($activity->note !== null)
                                <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $activity->note }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        @if ($canRespond)
            <aside class="order-first grid gap-5 xl:order-none">
                @if ($incident->status === 'open')
                    <form method="POST" action="{{ route('monitoring.incidents.update', [$project, $incident->id]) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="acknowledge">
                        <input type="hidden" name="version" value="{{ $incident->state_version }}">
                        <x-signal.ui.button type="submit" variant="primary" class="w-full">{{ __('Acknowledge') }}</x-signal.ui.button>
                    </form>
                @endif
                <x-signal.ui.card>
                    <form method="POST" action="{{ route('monitoring.incidents.update', [$project, $incident->id]) }}" class="grid gap-3 p-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="version" value="{{ $incident->state_version }}">
                        <x-signal.ui.select-field name="assignee_id" :label="__('Assigned to')">
                            <option value="">{{ __('No one') }}</option>
                            @foreach ($assignees as $member)
                                <option value="{{ $member->id }}" @selected($incident->assignee_id === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                    </form>
                </x-signal.ui.card>
                <x-signal.ui.card>
                    <form method="POST" action="{{ route('monitoring.incidents.update', [$project, $incident->id]) }}" class="grid gap-3 p-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="note">
                        <input type="hidden" name="version" value="{{ $incident->state_version }}">
                        <x-signal.ui.textarea-field name="note" :label="__('Add a note')" rows="3" maxlength="1000" />
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Add note') }}</x-signal.ui.button>
                    </form>
                </x-signal.ui.card>
            </aside>
        @endif
    </div>
</x-signal.layouts.project>
