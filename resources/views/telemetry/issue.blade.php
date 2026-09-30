@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$issue->title" :description="($issue->location ?? __('Unknown location')).' · '.$issue->status->label()">
    <div class="grid items-start gap-6 xl:grid-cols-3">
        <div class="grid gap-6 xl:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-signal.ui.stat :label="__('Occurrences')" :value="number_format($issue->occurrences)" />
                <x-signal.ui.stat :label="__('First seen')" :value="$issue->first_seen_at->diffForHumans()" />
                <x-signal.ui.stat :label="__('Last seen')" :value="$issue->last_seen_at->diffForHumans()" />
            </div>

            @if ($issue->details)
                <x-signal.ui.card class="p-5">
                    <h2 class="text-sm font-bold text-ink">{{ __('Details') }}</h2>
                    <x-signal.ui.code-block :code="$issue->details" class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-all text-xs" />
                </x-signal.ui.card>
            @endif

            <x-signal.ui.table :caption="__('Recent occurrences')">
                <x-slot:head><tr><th scope="col">{{ __('When (UTC)') }}</th><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('Event') }}</th></tr></x-slot:head>
                @forelse ($events as $event)
                    <tr>
                        <td class="whitespace-nowrap"><a class="text-primary hover:underline" href="{{ route('monitoring.events.show', [$project, $event->id]) }}">{{ $event->occurred_at->format('Y-m-d H:i:s') }}</a></td>
                        <td>{{ $event->environment->name }}</td>
                        <td class="break-all">{{ $event->name ?? $event->route ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-8 text-center text-muted">{{ __('No stored occurrences. Older ones may have passed the retention period.') }}</td></tr>
                @endforelse
            </x-signal.ui.table>

            <section class="grid gap-3" aria-labelledby="activity-heading">
                <h2 id="activity-heading" class="text-sm font-bold text-ink">{{ __('Activity') }}</h2>
                <ol class="grid gap-3">
                    @forelse ($activities as $activity)
                        <li class="rounded-panel border border-line bg-surface p-4 text-sm">
                            <div class="flex flex-wrap justify-between gap-2">
                                <p class="font-semibold text-ink">{{ __(ucfirst(str_replace('_', ' ', $activity->action))) }}</p>
                                <time class="text-xs text-muted">{{ $activity->created_at?->format('Y-m-d H:i:s') }} UTC</time>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $activity->actor->name ?? __('Automatic') }}</p>
                            @if ($activity->note)
                                <p class="mt-2 whitespace-pre-wrap break-words">{{ $activity->note }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-muted">{{ __('No activity yet.') }}</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <aside class="order-first grid gap-5 xl:order-none">
            <x-signal.ui.card class="grid gap-2 p-4 text-sm">
                <p class="flex flex-wrap gap-2">
                    <x-signal.ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-signal.ui.badge>
                    <x-signal.ui.badge :tone="$issue->severity === 'critical' ? 'danger' : 'neutral'">{{ __(ucfirst($issue->severity)) }}</x-signal.ui.badge>
                </p>
                @if ($issue->snoozed_until)
                    <p class="text-muted">{{ __('Snoozed until :time UTC', ['time' => $issue->snoozed_until->format('Y-m-d H:i')]) }}</p>
                @endif
                <p class="text-muted">{{ __('Assigned to: :name', ['name' => $issue->assignee->name ?? __('no one')]) }}</p>
                @if ($issue->environment)
                    <p class="text-muted">{{ __('First seen in :environment', ['environment' => $issue->environment->name]) }}</p>
                @endif
                @if ($issue->ticket_url)
                    <p class="text-muted">{{ __('Ticket:') }} <a href="{{ $issue->ticket_url }}" class="font-bold text-primary hover:underline" rel="noopener noreferrer" target="_blank">{{ $issue->ticket_key }}</a></p>
                @endif
            </x-signal.ui.card>

            @if ($canUpdate && ! $issue->ticket_url)
                <x-signal.ui.card>
                    @if ($trackers->isEmpty())
                        <p class="p-4 text-sm text-muted">{{ __('File tickets in GitHub Issues, Linear or Jira: connect a tracker on') }} <a href="{{ route('monitoring.setup', $project) }}#trackers" class="font-bold text-primary hover:underline">{{ __('Setup') }}</a>.</p>
                    @else
                        <form method="POST" action="{{ route('monitoring.issues.ticket', [$project, $issue->id]) }}" class="grid gap-3 p-4">
                            @csrf
                            <x-signal.ui.select-field name="tracker" :label="__('Create a ticket in')">
                                @foreach ($trackers as $tracker)
                                    <option value="{{ $tracker->id }}">{{ $tracker->name }} · {{ $tracker->destination() }}</option>
                                @endforeach
                            </x-signal.ui.select-field>
                            <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Create ticket') }}</x-signal.ui.button>
                        </form>
                    @endif
                </x-signal.ui.card>
            @endif

            @if ($canUpdate)
                <x-signal.ui.card>
                    <form method="POST" action="{{ route('monitoring.issues.update', [$project, $issue->id]) }}" class="grid gap-3 p-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $issue->state_version }}">
                        <x-signal.ui.select-field name="action" :label="__('Change')">
                            @if ($issue->status === \App\Enums\IssueStatus::Open)
                                <option value="resolve">{{ __('Resolve') }}</option>
                                <option value="snooze">{{ __('Snooze') }}</option>
                                <option value="ignore">{{ __('Ignore') }}</option>
                            @else
                                <option value="reopen">{{ __('Reopen') }}</option>
                            @endif
                        </x-signal.ui.select-field>
                        <x-signal.ui.select-field name="snooze_minutes" :label="__('Snooze for')">
                            @foreach ($snoozeOptions as $minutes => $label)
                                <option value="{{ $minutes }}">{{ __($label) }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.textarea-field name="note" :label="__('Note (optional)')" rows="2" maxlength="1000" />
                        <x-signal.ui.button type="submit" variant="primary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                    </form>
                </x-signal.ui.card>
                <x-signal.ui.card>
                    <form method="POST" action="{{ route('monitoring.issues.update', [$project, $issue->id]) }}" class="grid gap-3 p-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="version" value="{{ $issue->state_version }}">
                        <x-signal.ui.select-field name="assignee_id" :label="__('Assigned to')">
                            <option value="">{{ __('No one') }}</option>
                            @foreach ($assignees as $member)
                                <option value="{{ $member->id }}" @selected($issue->assignee_id === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button>
                    </form>
                </x-signal.ui.card>
            @endif
        </aside>
    </div>
</x-signal.layouts.project>
