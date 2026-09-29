@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$objective->name" :description="__($objective->indicatorLabel()).' · '.__(':target% target', ['target' => rtrim(rtrim(number_format($objective->target, 3), '0'), '.')]).' · '.__($objective->windowLabel()).' · '.$objective->environment->name">
    <div class="flex flex-wrap justify-end gap-2">
        @if ($canExport)
            <x-signal.ui.button :href="route('monitoring.objectives.export', [$project, $objective->id])" variant="secondary" size="sm">{{ __('Download CSV') }}</x-signal.ui.button>
        @endif
        @if ($canManage)
            <x-signal.ui.button :href="route('monitoring.objectives.edit', [$project, $objective->id])" data-modal-trigger="edit-objective" :data-modal-history-url="route('monitoring.objectives.show', [$project, $objective->id, 'dialog' => 'edit-objective'])" variant="secondary" size="sm">{{ __('Edit') }}</x-signal.ui.button>
            <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="archive-objective">{{ __('Archive') }}</x-signal.ui.button>
            <x-signal.overlays.delete-confirmation id="archive-objective" :route="route('monitoring.objectives.archive', [$project, $objective->id])" :title="__('Archive :objective?', ['objective' => $objective->name])" :description="__('Burn-rate rules that use it stop finding data until you change them.')" :submit-label="__('Archive')" />
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-signal.ui.stat :label="__('Compliance')" :value="$report['compliance'] !== null ? $report['compliance'].'%' : '—'" />
        <x-signal.ui.stat :label="__('Error budget remaining')" :value="match (true) { $report['budget_remaining'] === null => '—', $report['budget_remaining'] <= 0 => __('Exhausted'), default => $report['budget_remaining'].'%' }" />
        <x-signal.ui.stat :label="__('Good requests')" :value="number_format($report['good'])" />
        <x-signal.ui.stat :label="__('Bad requests')" :value="number_format($report['bad'])" />
    </div>
    <p class="text-xs text-muted">{{ __(':observed requests measured between :from and :until UTC; :unknown couldn’t be judged.', ['observed' => number_format($report['observed']), 'from' => $report['from']->format('Y-m-d H:i'), 'until' => $report['until']->format('Y-m-d H:i'), 'unknown' => number_format($report['unknown'])]) }}</p>

    <x-signal.ui.card class="grid gap-2 p-5 text-sm">
        @if ($burnRate)
            <p class="flex items-center gap-2 font-bold text-ink">{{ __('Burn-rate analysis') }}
                <x-signal.ui.badge :tone="match ($burnRate['status']) { 'critical' => 'danger', 'warning' => 'warning', 'healthy' => 'success', default => 'neutral' }">{{ __($burnRate['label']) }}</x-signal.ui.badge>
            </p>
            <p class="text-muted">{{ __($burnRate['message']) }}</p>
            @php($burn = fn (?float $rate): string => $rate === null ? '—' : number_format($rate, 2).'×')
            <p class="text-xs text-muted">{{ __('Last hour: :short · last 6 hours: :long', ['short' => $burn($burnRate['short']['burn_rate']), 'long' => $burn($burnRate['long']['burn_rate'])]) }}</p>
        @else
            <p class="font-bold text-ink">{{ __('Burn-rate analysis') }}</p>
            <p class="text-muted">{{ __('Burn-rate analysis comes with Monitoring Pro and above.') }}</p>
        @endif
    </x-signal.ui.card>
    @unless ($canExport)
        <p class="text-xs text-muted">{{ __('CSV reports come with Monitoring Team and Scale.') }}</p>
    @endunless

    @if ($canManage)
        <x-signal.overlays.page-modal id="edit-objective" :title="__('Edit :objective', ['objective' => $objective->name])" :src="route('monitoring.objectives.edit', [$project, $objective->id])" size="large" />
    @endif
</x-signal.layouts.project>
