@php
    $historyUrl = route('servers.commands.index', array_filter(['server' => $server, ...$filters], fn ($value) => $value !== null));
@endphp

<div class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Server operations') }}</p>
            <h3 class="mt-1 text-xl font-black text-primary">{{ __('Recent command history') }}</h3>
            <p class="mt-1 text-sm text-secondary">{{ __('Review execution state and retained output without leaving this server.') }}</p>
        </div>
        <x-ui.button :href="$historyUrl" variant="secondary">{{ __('Open full history') }}</x-ui.button>
    </div>

    <x-ui.insights id="server-command-history-dialog-insights" :summary="trans_choice(':count matching command|:count matching commands', $metrics['total'], ['count' => $metrics['total']])">
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-3">
            <x-ui.stat class="ui-card" :label="__('Commands')" :value="$metrics['total']" :description="__('Matching server history.')" />
            <x-ui.stat class="ui-card" :label="__('Active')" :value="$metrics['active']" :description="__('Queued or running commands.')" />
            <x-ui.stat class="ui-card" :label="__('Output retained')" :value="$metrics['output']" :description="__('Output available to download.')" />
        </dl>
    </x-ui.insights>

    @if ($executions->isEmpty())
        <x-ui.empty-state :title="array_filter($filters, fn ($value) => $value !== null) ? __('No commands match these filters') : __('No commands have been run on this server yet')" />
    @else
        <div class="space-y-3" aria-label="{{ __('Server command history') }}">
            @foreach ($executions as $execution)
                @php($statusTone = match ($execution->status) {
                    \App\Models\ServerCommandExecution::STATUS_SUCCEEDED => 'success',
                    \App\Models\ServerCommandExecution::STATUS_FAILED => 'danger',
                    \App\Models\ServerCommandExecution::STATUS_CANCELED => 'warning',
                    default => 'accent',
                })
                <article data-command-execution class="rounded-xl border border-primary bg-primary p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Command execution #:id', ['id' => $execution->id]) }}</p>
                            <code class="mt-2 block max-h-24 overflow-auto break-all rounded-lg bg-secondary px-3 py-2 text-xs text-primary">{{ $execution->command }}</code>
                        </div>
                        <x-ui.badge :tone="$statusTone">{{ $execution->status }}</x-ui.badge>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-secondary">
                        <span>{{ __('Queued :time', ['time' => $execution->created_at->diffForHumans()]) }}</span>
                        @if ($execution->finished_at)
                            <span>{{ __('Finished :time', ['time' => $execution->finished_at->diffForHumans()]) }}</span>
                        @endif
                        <span>{{ __('Duration: :duration', ['duration' => $execution->durationLabel() ?? __('Not recorded')]) }}</span>
                    </div>
                    @if ($execution->output !== null)
                        <div class="mt-3">
                            <x-ui.button :href="route('servers.commands.output', ['server' => $server, 'execution' => $execution])" variant="secondary">{{ __('Download output') }}</x-ui.button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
        @if ($executions->hasPages())
            <div class="pt-1">{{ $executions->links() }}</div>
        @endif
    @endif
</div>
