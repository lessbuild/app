@php
    $historyUrl = route('commands.index', array_filter($filters, fn ($value) => $value !== null));
@endphp

<section data-command-history-content aria-labelledby="active-command-history-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Server operations') }}</p>
            <h2 id="active-command-history-heading" class="mt-1 text-lg font-bold text-ink">{{ __('Active command history') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Review bounded command status without exposing command text or retained output.') }}</p>
        </div>
        <span class="text-xs text-muted">{{ trans_choice(':count active command|:count active commands', $metrics['active'], ['count' => $metrics['active']]) }}</span>
    </div>

    <x-signal.ui.insights
        id="active-command-history-insights"
        class="mt-5"
        :summary="trans_choice(':count active command|:count active commands', $metrics['active'], ['count' => $metrics['active']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-signal.ui.stat class="ui-card" :label="__('Active')" :value="$metrics['active']" :description="__('Queued or running commands.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Matching commands')" :value="$metrics['total']" :description="__('Owner-scoped commands in this view.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Latest command')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching command recorded.')" />
        </dl>
    </x-signal.ui.insights>

    <div class="mt-5 space-y-3">
        @forelse ($executions as $execution)
            <x-signal.ui.card as="article" class="p-4" data-command-execution>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="ui-eyebrow text-[0.65rem]">{{ __('Execution #:id', ['id' => $execution->id]) }}</p>
                        <h3 class="mt-1 font-semibold text-ink">{{ $execution->server->label }}</h3>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-signal.ui.badge tone="accent">{{ $execution->status }}</x-signal.ui.badge>
                        <x-signal.ui.badge tone="{{ $execution->output_available ? 'success' : 'neutral' }}">{{ $execution->output_available ? __('Retained') : __('Not retained') }}</x-signal.ui.badge>
                    </div>
                </div>
                <dl class="mt-3 grid gap-3 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Queued') }}</dt>
                        <dd class="mt-1 text-ink">{{ $execution->created_at->diffForHumans() }}</dd>
                    </div>
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Started') }}</dt>
                        <dd class="mt-1 text-ink">{{ $execution->started_at?->diffForHumans() ?? __('Not started') }}</dd>
                    </div>
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Duration') }}</dt>
                        <dd class="mt-1 text-ink">{{ $execution->durationLabel() ?? __('Not recorded') }}</dd>
                    </div>
                </dl>
                <div class="mt-3">
                    <x-signal.ui.button :href="route('servers.commands.index', ['server' => $execution->server, 'execution' => $execution->id])" variant="ghost">{{ __('Open server history') }}</x-signal.ui.button>
                </div>
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state
                :title="__('No commands match these filters')"
                :description="__('Refresh the Command Center for the latest command state.')"
            />
        @endforelse
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm">{{ $executions->links() }}</div>
        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button :href="$historyUrl" variant="secondary">{{ __('Open Command Center') }}</x-signal.ui.button>
        </div>
    </div>
</section>
