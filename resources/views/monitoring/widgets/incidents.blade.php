@if ($incidents->isEmpty())
    <p class="mt-3 text-sm text-muted">{{ __('No open incidents.') }}</p>
@else
    <ul class="mt-3 divide-y divide-line">
        @foreach ($incidents as $incident)
            <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <a href="{{ route('monitoring.incidents.show', [$incident->project_id, $incident->id]) }}" class="font-bold text-ink hover:underline">{{ $incident->title }}</a>
                    <p class="text-xs text-muted">{{ $incident->project->name }} · {{ __('since :time', ['time' => $incident->opened_at->diffForHumans()]) }}</p>
                </div>
                <x-signal.ui.badge :tone="$incident->status === 'acknowledged' ? 'warning' : 'danger'">{{ __($incident->statusLabel()) }}</x-signal.ui.badge>
            </li>
        @endforeach
    </ul>
@endif
