@extends('monitor::layouts.app')
@section('title', 'Issues')
@section('breadcrumb', 'Issues')
@section('content')
<div class="space-y-6">
    <x-monitor::ui.page-header eyebrow="Issue inbox" title="Issues" description="Investigate failures, assign a teammate, and track every state change.">
        <x-slot:actions><x-monitor::ui.button :href="route('monitor.events.index', ['type' => 'exception'])" variant="quiet">Explore exception events →</x-monitor::ui.button></x-slot:actions>
    </x-monitor::ui.page-header>
    <section aria-label="Workspace issue totals" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach($statuses as $status)
            <x-signal.ui.card as="a" href="{{ route('monitor.issues.index', ['status' => $status->value]) }}" class="p-5 transition hover:border-primary dark:hover:border-primary">
                <x-monitor::ui.badge :tone="$status->tone()">{{ $status->label() }}</x-monitor::ui.badge>
                <p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($totals->get($status->value)?->total ?? 0) }}</p>
                <p class="mt-1 text-xs text-muted dark:text-subtle">{{ number_format($totals->get($status->value)?->critical ?? 0) }} critical</p>
            </x-signal.ui.card>
        @endforeach
    </section>
    <p class="text-xs text-muted dark:text-subtle">Totals cover all issues in this workspace’s unarchived applications, independently of the filters below.</p>
    <x-signal.ui.panel as="section" class="overflow-hidden">
        <form method="GET" action="{{ route('monitor.issues.index') }}" class="grid gap-4 border-b border-line p-5 sm:grid-cols-2 lg:grid-cols-3 dark:border-line">
            <x-monitor::ui.input name="q" label="Search title or location" type="search" :value="$filters['q'] ?? ''" maxlength="255" placeholder="Search issues…" />
            <x-monitor::ui.select name="status" label="Status" :value="$filters['status']" :options="['all' => 'All statuses', ...collect($statuses)->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()]" />
            <x-monitor::ui.select name="application" label="Application" :value="$filters['application'] ?? ''" :options="$applications->pluck('name', 'id')->all()" placeholder="All applications" />
            <x-monitor::ui.select name="severity" label="Severity" :value="$filters['severity'] ?? ''" :options="['critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info', 'debug' => 'Debug']" placeholder="All severities" />
            <x-monitor::ui.select name="ownership" label="Ownership" :value="$filters['ownership']" :options="['any' => 'Anyone', 'mine' => 'Assigned to me', 'unassigned' => 'Unassigned']" />
            <div class="flex items-end gap-4"><x-monitor::ui.button>Filter issues</x-monitor::ui.button><a href="{{ route('monitor.issues.index') }}" class="py-2.5 text-xs font-semibold text-muted hover:underline dark:text-subtle">Reset</a></div>
        </form>
        <div class="border-b border-line px-5 py-4 text-xs text-muted dark:border-line dark:text-subtle">{{ number_format($issues->total()) }} matching issues · newest occurrence first · manual refresh</div>
        <div class="overflow-x-auto">
            <x-monitor::ui.table caption="Issues and latest occurrences" :framed="false">
                <x-slot:head>
                    <tr><th scope="col" class="font-semibold">Issue / application</th><th scope="col" class="font-semibold">Status</th><th scope="col" class="font-semibold">Owner</th><th scope="col" class="font-semibold">Occurrences</th><th scope="col" class="font-semibold">Last seen (UTC)</th></tr>
                </x-slot:head>
                    @forelse($issues as $issue)
                        <tr class="hover:bg-surface-muted dark:hover:bg-surface-muted">
                            <th scope="row" class="max-w-md text-left font-normal">
                                <a href="{{ route('monitor.issues.show', $issue) }}" class="block truncate text-sm font-bold text-ink hover:text-primary dark:text-ink dark:hover:text-primary">{{ $issue->title }}</a>
                                <p class="mt-1 truncate text-muted dark:text-subtle">{{ $issue->location ?: 'No location reported' }}</p>
                                <p class="mt-2 text-muted dark:text-subtle">{{ $issue->application->name }} · {{ ucfirst($issue->severity) }}</p>
                            </th>
                            <td><x-monitor::ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-monitor::ui.badge></td>
                            <td>{{ $issue->assignee?->name ?? 'Unassigned' }}</td>
                            <td class="font-semibold tabular-nums">{{ number_format($issue->occurrences) }}</td>
                            <td class="whitespace-nowrap text-muted dark:text-subtle"><time datetime="{{ $issue->last_seen_at->toISOString() }}">{{ $issue->last_seen_at->utc()->format('M j, Y H:i:s') }}</time></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-12 text-center text-muted dark:text-subtle">No issues match these filters.</td></tr>
                    @endforelse
            </x-monitor::ui.table>
        </div>
        @if($issues->hasPages())<div class="border-t border-line p-5 dark:border-line">{{ $issues->links() }}</div>@endif
    </x-signal.ui.panel>
</div>
@endsection
