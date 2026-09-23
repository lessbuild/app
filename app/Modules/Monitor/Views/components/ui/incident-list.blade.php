@props(['incidents'])
<div class="ui-card overflow-x-auto">
    <x-monitor::ui.table caption="Incidents, ownership and recovery history" :framed="false">
        <x-slot:head><tr><th scope="col">Incident</th><th scope="col">Status</th><th scope="col">Owner</th><th scope="col">Opened (UTC)</th><th scope="col">Closed (UTC)</th></tr></x-slot:head>
            @forelse($incidents as $incident)
                <tr><td class="max-w-sm"><a class="break-words font-semibold text-primary hover:underline dark:text-primary" href="{{ route('monitor.incidents.show', $incident) }}">#{{ $incident->id }} · {{ $incident->title }}</a></td><td><x-monitor::ui.badge :tone="$incident->status === 'open' ? 'red' : ($incident->status === 'acknowledged' ? 'amber' : 'slate')">{{ $incident->statusLabel() }}</x-monitor::ui.badge></td><td>{{ $incident->assignee?->name ?? 'Unassigned' }}</td><td class="whitespace-nowrap">{{ $incident->opened_at->format('Y-m-d H:i:s') }}</td><td class="whitespace-nowrap">{{ $incident->resolved_at?->format('Y-m-d H:i:s') ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-12 text-center text-muted dark:text-subtle">No incidents match this view.</td></tr>
            @endforelse
    </x-monitor::ui.table>
</div>
<div>{{ $incidents->links() }}</div>
