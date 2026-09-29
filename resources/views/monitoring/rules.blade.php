@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Alert rules')" :description="__('Open an incident when error rates, latency, exceptions, log patterns, metrics or SLO burn cross a threshold.')">
    @include('monitoring._alerts-tabs')

    @if ($canManage)
        <div class="flex justify-end">
            <x-signal.ui.button :href="route('monitoring.rules.create', $project)" data-modal-trigger="add-rule" :data-modal-history-url="route('monitoring.rules', [$project, 'dialog' => 'add-rule'])" variant="primary">{{ __('Add a rule') }}</x-signal.ui.button>
        </div>
    @endif

    @if ($rules === [])
        <x-signal.ui.empty-state icon="clock" :title="__('No alert rules yet')" :description="__('Rules check the telemetry your apps send every minute. Try “request error rate at or above 5% for 5 minutes”.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Alert rules') }}">
                @foreach ($rules as $rule)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('monitoring.rules.show', [$project, $rule->id]) }}" class="font-extrabold text-ink hover:underline">{{ $rule->name }}</a>
                            <p class="mt-0.5 text-xs text-muted">{{ __($rule->metric->label()) }} {{ $rule->comparisonLabel() }} {{ $rule->thresholdValue() }} · {{ trans_choice(':count minute|:count minutes', $rule->window_minutes, ['count' => $rule->window_minutes]) }} · {{ $rule->environment->name }}</p>
                        </div>
                        <x-signal.ui.badge :tone="! $rule->enabled ? 'neutral' : match ($rule->evaluation_state) { 'breaching' => 'danger', 'healthy' => 'success', default => 'neutral' }">
                            {{ $rule->enabled ? __(ucfirst(str_replace('_', ' ', $rule->evaluation_state))) : __('Paused') }}
                        </x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($canManage)
        <x-signal.overlays.page-modal id="add-rule" :title="__('Add an alert rule')" :src="route('monitoring.rules.create', $project)" size="large" />
    @endif
</x-signal.layouts.project>
