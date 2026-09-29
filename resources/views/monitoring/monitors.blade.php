@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Monitors')" :description="__('Uptime, DNS, TLS and TCP checks, plus heartbeats from your jobs and signals from your queues.')">
    @if ($canManage)
        <div class="flex justify-end">
            <x-signal.ui.button :href="route('monitoring.monitors.create', $project)" data-modal-trigger="add-monitor" :data-modal-history-url="route('monitoring.monitors', [$project, 'dialog' => 'add-monitor'])" variant="primary">{{ __('Add a monitor') }}</x-signal.ui.button>
        </div>
    @endif

    @if ($monitors === [])
        <x-signal.ui.empty-state icon="check-circle" :title="__('Add your first monitor')" :description="__('Check a URL every few minutes, watch a certificate or DNS record, or have a cron job report in. When something breaks, an incident opens and your alert destinations hear about it.')" />
    @else
        <div id="monitors-live" data-live-region data-live-interval="15000">
            <x-signal.ui.card class="overflow-hidden">
                <ul class="divide-y divide-line" aria-label="{{ __('Monitors') }}">
                    @foreach ($monitors as $monitor)
                        @php($health = $monitor->healthLabel())
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <a href="{{ route('monitoring.monitors.show', [$project, $monitor->id]) }}" class="font-extrabold text-ink hover:underline">{{ $monitor->name }}</a>
                                <p class="mt-0.5 break-all text-xs text-muted">{{ $monitor->typeLabel() }} · {{ $monitor->environment->name }} · {{ $monitor->targetLabel() }}</p>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-muted">
                                @if ($monitor->checked_at)
                                    <span>{{ __('Checked :time', ['time' => $monitor->checked_at->diffForHumans()]) }}</span>
                                @endif
                                <x-signal.ui.badge :tone="match ($health) { 'Up' => 'success', 'Down' => 'danger', default => 'neutral' }">{{ __($health) }}</x-signal.ui.badge>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-signal.ui.card>
        </div>
    @endif

    @if ($canManage)
        <x-signal.overlays.page-modal id="add-monitor" :title="__('Add a monitor')" :src="route('monitoring.monitors.create', $project)" size="wide" />
    @endif
</x-signal.layouts.project>
