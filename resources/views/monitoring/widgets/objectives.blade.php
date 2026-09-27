@if ($objectives === [])
    <p class="mt-3 text-sm text-muted">{{ __('No SLOs yet.') }}</p>
@else
    <ul class="mt-3 divide-y divide-line">
        @foreach ($objectives as ['objective' => $objective, 'report' => $report])
            <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <a href="{{ route('monitoring.objectives.show', [$objective->environment->project_id, $objective->id]) }}" class="font-bold text-ink hover:underline">{{ $objective->name }}</a>
                    <p class="text-xs text-muted">{{ $objective->environment->project->name }} · {{ __(':target% target', ['target' => rtrim(rtrim(number_format($objective->target, 3), '0'), '.')]) }} · {{ $report['compliance'] === null ? __('no data') : __(':value% now', ['value' => number_format($report['compliance'], 2)]) }}</p>
                </div>
                <x-signal.ui.badge :tone="match (true) { $report['budget_remaining'] === null => 'neutral', $report['budget_remaining'] <= 0 => 'danger', $report['budget_remaining'] < 25 => 'warning', default => 'success' }">{{ $report['budget_remaining'] === null ? __('No data') : ($report['budget_remaining'] <= 0 ? __('Budget exhausted') : __(':value% budget left', ['value' => number_format($report['budget_remaining'], 1)])) }}</x-signal.ui.badge>
            </li>
        @endforeach
    </ul>
@endif
