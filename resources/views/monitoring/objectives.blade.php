@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Service level objectives')" :description="__('Availability or latency targets over a rolling window, and how much error budget is left.')">
    @if ($canManage)
        <div class="flex justify-end">
            <x-signal.ui.button :href="route('monitoring.objectives.create', $project)" variant="primary">{{ __('Add an objective') }}</x-signal.ui.button>
        </div>
    @endif

    @if ($objectives === [])
        <x-signal.ui.empty-state icon="check-circle" :title="__('No objectives yet')" :description="__('For example: 99.9% of requests succeed over 30 days, or 95% finish within 300 ms.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Objectives') }}">
                @foreach ($objectives as $objective)
                    @php($report = $reports[$objective->id])
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('monitoring.objectives.show', [$project, $objective->id]) }}" class="font-extrabold text-ink hover:underline">{{ $objective->name }}</a>
                            <p class="mt-0.5 text-xs text-muted">{{ __($objective->indicatorLabel()) }} · {{ __(':target% target', ['target' => rtrim(rtrim(number_format($objective->target, 3), '0'), '.')]) }} · {{ __($objective->scopeLabel()) }} · {{ $objective->environment->name }}</p>
                        </div>
                        <span class="flex items-center gap-3 text-sm">
                            <span class="text-muted">{{ $report['compliance'] !== null ? $report['compliance'].'%' : '—' }}</span>
                            <x-signal.ui.badge :tone="! $objective->enabled ? 'neutral' : match ($report['status']) { 'healthy' => 'success', 'warning' => 'warning', 'exhausted' => 'danger', default => 'neutral' }">{{ $objective->enabled ? __(ucfirst(str_replace('_', ' ', $report['status']))) : __('Paused') }}</x-signal.ui.badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.project>
