@php
    $historyUrl = route('commands.index', array_filter($filters, fn ($value) => $value !== null));
@endphp

<section data-command-history-content aria-labelledby="active-command-history-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Server operations') }}</p>
            <h2 id="active-command-history-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Active command history') }}</h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Review bounded command status without exposing command text or retained output.') }}</p>
        </div>
        <span class="text-xs text-secondary">{{ trans_choice(':count active command|:count active commands', $metrics['active'], ['count' => $metrics['active']]) }}</span>
    </div>

    <x-ui.insights
        id="active-command-history-insights"
        class="mt-5"
        :summary="trans_choice(':count active command|:count active commands', $metrics['active'], ['count' => $metrics['active']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.stat class="ui-card" :label="__('Active')" :value="$metrics['active']" :description="__('Queued or running commands.')" />
            <x-ui.stat class="ui-card" :label="__('Matching commands')" :value="$metrics['total']" :description="__('Owner-scoped commands in this view.')" />
            <x-ui.stat class="ui-card" :label="__('Latest command')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching command recorded.')" />
        </dl>
    </x-ui.insights>

    <div class="mt-5 space-y-3">
        @forelse ($executions as $execution)
            <article data-command-execution class="rounded-xl border border-primary bg-primary p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Execution #:id', ['id' => $execution->id]) }}</p>
                        <h3 class="mt-1 font-semibold text-primary">{{ $execution->server->label }}</h3>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.badge tone="accent">{{ $execution->status }}</x-ui.badge>
                        <x-ui.badge tone="{{ $execution->output_available ? 'success' : 'neutral' }}">{{ $execution->output_available ? __('Retained') : __('Not retained') }}</x-ui.badge>
                    </div>
                </div>
                <dl class="mt-3 grid gap-3 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="font-bold uppercase tracking-wide text-secondary">{{ __('Queued') }}</dt>
                        <dd class="mt-1 text-primary">{{ $execution->created_at->diffForHumans() }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold uppercase tracking-wide text-secondary">{{ __('Started') }}</dt>
                        <dd class="mt-1 text-primary">{{ $execution->started_at?->diffForHumans() ?? __('Not started') }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold uppercase tracking-wide text-secondary">{{ __('Duration') }}</dt>
                        <dd class="mt-1 text-primary">{{ $execution->durationLabel() ?? __('Not recorded') }}</dd>
                    </div>
                </dl>
                <div class="mt-3">
                    <x-ui.button :href="route('servers.commands.index', ['server' => $execution->server, 'execution' => $execution->id])" variant="ghost">{{ __('Open server history') }}</x-ui.button>
                </div>
            </article>
        @empty
            <x-ui.empty-state
                :title="__('No commands match these filters')"
                :description="__('Refresh the Command Center for the latest command state.')"
            />
        @endforelse
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm">{{ $executions->links() }}</div>
        <div class="flex flex-wrap gap-3">
            <x-ui.button :href="$historyUrl" variant="secondary">{{ __('Open Command Center') }}</x-ui.button>
        </div>
    </div>
</section>
