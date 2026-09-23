@extends('monitor::layouts.app')
@section('title', 'Issue · '.$issue->title)
@section('breadcrumb', 'Issue details')
@section('content')
<div class="space-y-6">
    <a href="{{ route('monitor.issues.index') }}" class="text-xs font-bold text-primary hover:underline dark:text-primary">← Issue inbox</a>
    <x-monitor::ui.page-header :title="$issue->title" :description="ucfirst($issue->severity).' · '.$issue->application->name">
        <x-slot:metadata><code class="wrap-anywhere text-xs text-muted">{{ $issue->location ?: 'No location reported' }}</code></x-slot:metadata>
        <x-slot:actions><x-monitor::ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-monitor::ui.badge></x-slot:actions>
    </x-monitor::ui.page-header>
    <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="ui-card p-5"><p class="text-xs text-muted dark:text-subtle">Lifetime occurrences</p><p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($issue->occurrences) }}</p></div>
        <div class="ui-card p-5"><p class="text-xs text-muted dark:text-subtle">Linked searchable events</p><p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($events->total()) }}</p></div>
        <div class="ui-card p-5"><p class="text-xs text-muted dark:text-subtle">Assigned owner</p><p class="mt-3 break-words text-sm font-bold">{{ $issue->assignee?->name ?? 'Unassigned' }}</p></div>
        <div class="ui-card p-5"><p class="text-xs text-muted dark:text-subtle">Last seen (UTC)</p><time datetime="{{ $issue->last_seen_at->toISOString() }}" class="mt-3 block text-sm font-bold">{{ $issue->last_seen_at->utc()->format('M j, Y H:i:s') }}</time></div>
    </section>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(18rem,0.7fr)]">
        <div class="min-w-0 space-y-6">
            <section id="occurrences" class="ui-panel overflow-hidden">
                <div class="space-y-2 border-b border-line p-5 dark:border-line"><h2 class="font-bold">Linked occurrences</h2><p class="text-xs leading-5 text-muted dark:text-subtle">Only events explicitly linked to this issue are shown. Older events are not retrospectively guessed. Archived environments and removed samples are excluded, so this count can differ from lifetime occurrences.</p></div>
                <div class="divide-y divide-line dark:divide-line">
                    @forelse($events as $event)
                        <a href="{{ route('monitor.events.show', $event) }}" class="flex flex-col gap-3 p-5 hover:bg-surface-muted sm:flex-row sm:items-center dark:hover:bg-surface-muted">
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold">{{ $event->name }}</span><span class="mt-1 block truncate text-xs text-muted dark:text-subtle">{{ $event->environment->name }} · {{ $event->route ?: 'No route reported' }}</span></span>
                            <span class="shrink-0 text-xs text-muted dark:text-subtle"><time datetime="{{ $event->occurred_at->toISOString() }}">{{ $event->occurred_at->utc()->format('M j, H:i:s') }} UTC</time><span class="mt-1 block">{{ $event->duration_ms === null ? 'Duration not reported' : number_format($event->duration_ms, 2).' ms' }}</span></span>
                        </a>
                    @empty
                        <p class="p-8 text-center text-sm text-muted dark:text-subtle">No linked event samples are available.</p>
                    @endforelse
                </div>
                @if($events->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $events->fragment('occurrences')->links() }}</div>@endif
            </section>
            <section class="ui-panel space-y-4 p-5">
                <h2 class="font-bold">Exception context</h2>
                <p class="whitespace-pre-wrap break-words rounded-control bg-surface-muted p-4 text-sm leading-6 text-muted dark:bg-surface-muted dark:text-muted">{{ $issue->details ?: 'No additional context reported.' }}</p>
                <p class="text-xs text-muted dark:text-subtle">Context from the first recorded occurrence. Inspect linked events for per-occurrence data.</p>
                <pre class="library-code">{{ $metadataJson }}</pre>
            </section>
            <section id="activity" class="ui-panel overflow-hidden">
                <div class="border-b border-line p-5 dark:border-line"><h2 class="font-bold">Activity history</h2><p class="mt-1 text-xs text-muted dark:text-subtle">Triage and automatic state changes, newest first. Times are UTC.</p></div>
                <ol class="divide-y divide-line dark:divide-line">
                    @forelse($activities as $activity)
                        <li class="space-y-2 p-5">
                            <p class="text-sm font-semibold">{{ $activity->label() }}</p>
                            <p class="text-xs text-muted dark:text-subtle">{{ $activity->actor?->name ?? (in_array($activity->action, ['detected', 'regressed', 'snooze_expired'], true) ? 'Monitor' : 'Former member') }} · <time datetime="{{ $activity->created_at->toISOString() }}">{{ $activity->created_at->utc()->format('Y-m-d H:i:s') }}</time></p>
                            @if($activity->action === 'assign' && ($activity->metadata['assignee_id'] ?? null))
                                <p class="text-xs text-muted dark:text-subtle">Assigned to {{ $assignees->firstWhere('id', $activity->metadata['assignee_id'])?->name ?? 'a former contributor' }}.</p>
                            @endif
                            @if($activity->action === 'snooze' && is_string($activity->metadata['snoozed_until'] ?? null))
                                <p class="text-xs text-muted dark:text-subtle">Until {{ $activity->metadata['snoozed_until'] }}.</p>
                            @endif
                            @if($activity->note)<p class="whitespace-pre-wrap break-words text-sm leading-6 text-muted dark:text-muted">{{ $activity->note }}</p>@endif
                        </li>
                    @empty
                        <li class="p-8 text-center text-sm text-muted dark:text-subtle">No recorded activity yet. Historical actions from before workflow tracking are unavailable.</li>
                    @endforelse
                </ol>
                @if($activities->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $activities->fragment('activity')->links() }}</div>@endif
            </section>
        </div>
        <aside class="space-y-6">
            <section class="ui-panel space-y-4 p-5">
                <h2 class="font-bold">Issue ownership</h2>
                @if($canUpdate)
                    <form method="POST" action="{{ route('monitor.issues.update', $issue) }}" class="space-y-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="version" value="{{ $issue->state_version }}">
                        <x-monitor::ui.select name="assignee_id" label="Workspace contributor" :value="$issue->assignee_id" :options="$assignees->pluck('name', 'id')->all()" placeholder="Unassigned" />
                        <x-monitor::ui.button variant="secondary">Update owner</x-monitor::ui.button>
                    </form>
                @else
                    <p class="text-sm">{{ $issue->assignee?->name ?? 'Unassigned' }}</p>
                    <p class="text-xs leading-5 text-muted dark:text-subtle">Your viewer role is read-only. A workspace contributor can update this issue.</p>
                @endif
            </section>
            @if($canUpdate)
                <section class="ui-panel space-y-4 p-5">
                    <h2 class="font-bold">Triage issue</h2>
                    <form method="POST" action="{{ route('monitor.issues.update', $issue) }}" class="space-y-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="version" value="{{ $issue->state_version }}">
                        <x-monitor::ui.select name="action" label="Action" :options="['resolve' => 'Mark resolved', 'reopen' => 'Reopen', ...(in_array($issue->status->value, ['open', 'snoozed'], true) ? ['snooze' => 'Snooze'] : []), 'ignore' => 'Ignore future occurrences']" />
                        <x-monitor::ui.select name="snooze_minutes" label="Snooze duration (only for snooze)" :options="$snoozeOptions" :value="60" />
                        <x-monitor::ui.textarea name="note" label="Optional note" maxlength="1000" />
                        <x-monitor::ui.button>Apply change</x-monitor::ui.button>
                    </form>
                    <p class="text-xs leading-5 text-muted dark:text-subtle">Resolved issues reopen for a new failure occurring and received after resolution. Snoozed issues reopen when their deadline passes. Ignored issues keep counting occurrences but stay ignored.</p>
                    <p class="text-xs leading-5 text-muted dark:text-subtle">Reopen resolved or ignored issues before snoozing. Unchanged actions do not add notes. If another teammate changes this issue, refresh before submitting.</p>
                </section>
            @endif
            <section class="ui-panel space-y-4 p-5">
                <h2 class="font-bold">Issue details</h2>
                <dl class="space-y-4 text-xs">
                    <div class="space-y-1"><dt class="text-muted dark:text-subtle">Latest environment</dt><dd>{{ $issue->environment?->name ?? 'Archived or unavailable' }}</dd></div>
                    <div class="space-y-1"><dt class="text-muted dark:text-subtle">First seen (UTC)</dt><dd>{{ $issue->first_seen_at->utc()->format('Y-m-d H:i:s') }}</dd></div>
                    @if($issue->resolved_at)<div class="space-y-1"><dt class="text-muted dark:text-subtle">Resolved (UTC)</dt><dd>{{ $issue->resolved_at->utc()->format('Y-m-d H:i:s') }}</dd></div>@endif
                    @if($issue->snoozed_until)<div class="space-y-1"><dt class="text-muted dark:text-subtle">Snoozed until (UTC)</dt><dd>{{ $issue->snoozed_until->utc()->format('Y-m-d H:i:s') }}</dd></div>@endif
                    @if($issue->status->value === 'snoozed' && $issue->snoozed_until === null)<div class="leading-5 text-muted dark:text-subtle">This legacy snooze has no recorded deadline. Set a new duration or reopen the issue.</div>@endif
                    @if($issue->status->value === 'resolved' && $issue->resolved_at === null)<div class="leading-5 text-muted dark:text-subtle">This legacy resolution has no recorded time. A newer occurrence than its last seen time will reopen it.</div>@endif
                    <div class="space-y-1"><dt class="text-muted dark:text-subtle">Fingerprint</dt><dd class="break-all font-mono">{{ $issue->fingerprint }}</dd></div>
                </dl>
            </section>
        </aside>
    </div>
</div>
@endsection
