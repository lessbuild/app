@if ($monitors->isEmpty())
    <p class="mt-3 text-sm text-muted">{{ __('No monitors yet.') }}</p>
@else
    <ul class="mt-3 divide-y divide-line">
        @foreach ($monitors as $monitor)
            @php($health = $monitor->healthLabel())
            <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <a href="{{ route('monitoring.monitors.show', [$monitor->environment->project_id, $monitor->id]) }}" class="font-bold text-ink hover:underline">{{ $monitor->name }}</a>
                    <p class="text-xs text-muted">{{ __($monitor->typeLabel()) }} · {{ $monitor->environment->project->name }} / {{ $monitor->environment->name }}</p>
                </div>
                <x-signal.ui.badge :tone="match ($health) { 'Up' => 'success', 'Down' => 'danger', 'Paused' => 'neutral', default => 'warning' }">{{ __($health) }}</x-signal.ui.badge>
            </li>
        @endforeach
    </ul>
@endif
