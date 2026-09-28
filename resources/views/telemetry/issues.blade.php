@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Issues')" :description="__('Errors grouped by what caused them. A new occurrence reopens a resolved issue.')">
    <form method="GET" action="{{ route('monitoring.issues', $project) }}" class="flex flex-wrap items-end gap-3" role="search">
        <x-signal.ui.input-field name="q" :label="__('Search')" :value="$filters['q'] ?? null" :restore="false" placeholder="{{ __('Title or location') }}" field-class="min-w-56 flex-1" />
        <x-signal.ui.select-field name="status" :label="__('Status')">
            <option value="all" @selected($filters['status'] === 'all')>{{ __('All') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.select-field name="ownership" :label="__('Assigned')">
            @foreach (['any' => __('Anyone'), 'mine' => __('To me'), 'unassigned' => __('No one')] as $value => $label)
                <option value="{{ $value }}" @selected($filters['ownership'] === $value)>{{ $label }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <x-signal.ui.button type="submit" variant="secondary">{{ __('Filter') }}</x-signal.ui.button>
    </form>
    @error('saved_view_name')<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    <x-signal.ui.saved-views page="monitoring.issues" :parameters="['project' => $project->id]" />

    @if ($issues->isEmpty())
        <x-signal.ui.empty-state icon="check-circle" :title="__('No issues here')" :description="__('Exceptions your apps send become issues. Connect an app on the Setup page to start collecting them.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Issues') }}">
                @foreach ($issues as $issue)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('monitoring.issues.show', [$project, $issue->id]) }}" class="font-extrabold text-ink hover:underline">{{ $issue->title }}</a>
                            <p class="mt-0.5 break-all text-xs text-muted">
                                {{ $issue->location ?? __('Unknown location') }} · {{ trans_choice(':count occurrence|:count occurrences', $issue->occurrences, ['count' => number_format($issue->occurrences)]) }} · {{ __('last :time', ['time' => $issue->last_seen_at->diffForHumans()]) }}
                                @if ($issue->assignee) · {{ $issue->assignee->name }}@endif
                            </p>
                        </div>
                        <span class="flex gap-2">
                            <x-signal.ui.badge :tone="$issue->severity === 'critical' ? 'danger' : 'neutral'">{{ __(ucfirst($issue->severity)) }}</x-signal.ui.badge>
                            <x-signal.ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-signal.ui.badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
        @include('telemetry._pager', ['paginator' => $issues])
    @endif
</x-signal.layouts.project>
