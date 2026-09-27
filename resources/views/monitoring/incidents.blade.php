@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Incidents')" :description="__('An incident opens when a monitor fails enough checks in a row, and closes when it recovers.')">
    <nav aria-label="{{ __('Incident status') }}" class="flex gap-2">
        <x-signal.ui.button :href="route('monitoring.incidents', $project)" :variant="$status === 'open' ? 'soft' : 'quiet'" size="sm" :aria-current="$status === 'open' ? 'page' : null">{{ __('Open') }}</x-signal.ui.button>
        <x-signal.ui.button :href="route('monitoring.incidents', [$project, 'status' => 'resolved'])" :variant="$status === 'resolved' ? 'soft' : 'quiet'" size="sm" :aria-current="$status === 'resolved' ? 'page' : null">{{ __('Closed') }}</x-signal.ui.button>
    </nav>

    @if ($incidents === [])
        <x-signal.ui.empty-state icon="check-circle" :title="$status === 'open' ? __('No open incidents') : __('No closed incidents yet')" :description="$status === 'open' ? __('Everything your monitors check is passing, or hasn’t failed enough times in a row to open an incident.') : __('Closed incidents stay here for reference.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Incidents') }}">
                @foreach ($incidents as $incident)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('monitoring.incidents.show', [$project, $incident->id]) }}" class="font-extrabold text-ink hover:underline">{{ $incident->title }}</a>
                            <p class="mt-0.5 text-xs text-muted">
                                #{{ $incident->id }} · {{ __('Opened :time', ['time' => $incident->opened_at->diffForHumans()]) }}
                                @if ($incident->assignee) · {{ __('Assigned to :name', ['name' => $incident->assignee->name]) }}@endif
                            </p>
                        </div>
                        <x-signal.ui.badge :tone="match ($incident->status) { 'open' => 'danger', 'acknowledged' => 'warning', default => 'neutral' }">{{ __($incident->statusLabel()) }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.project>
