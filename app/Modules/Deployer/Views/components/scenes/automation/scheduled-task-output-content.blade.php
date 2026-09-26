@php
    $statusTone = match ((string) $run->status) {
        'succeeded' => 'success',
        'failed' => 'danger',
        'canceled' => 'warning',
        default => 'accent',
    };
@endphp

<div data-task-run-output class="space-y-5 p-4 text-ink sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow">{{ __('Scheduled task') }}</p>
            <h3 class="mt-1 text-xl font-extrabold text-ink">{{ $task->name }}</h3>
            <p class="mt-1 text-sm text-muted">{{ $task->environment->project->name }} · {{ $task->environment->name }}</p>
        </div>
        <x-signal.ui.badge :tone="$statusTone">{{ $run->status }}</x-signal.ui.badge>
    </div>

    <dl class="grid gap-3 sm:grid-cols-3">
        <x-signal.ui.stat class="ui-panel" :label="__('Queued')" :value="$run->created_at?->diffForHumans() ?? __('Not recorded')" :description="__('When this run was queued.')" />
        <x-signal.ui.stat class="ui-panel" :label="__('Finished')" :value="$run->finished_at?->diffForHumans() ?? __('Not finished')" :description="__('When the remote command completed.')" />
        <x-signal.ui.stat class="ui-panel" :label="__('Duration')" :value="$run->duration_ms !== null ? number_format($run->duration_ms).' ms' : __('Not recorded')" :description="__('Recorded remote execution time.')" />
    </dl>

    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h4 class="font-bold text-ink">{{ __('Retained output') }}</h4>
                <p class="mt-1 text-xs text-muted">{{ __('Output is encrypted at rest and shown only to authorized workspace members.') }}</p>
            </div>
            <x-signal.ui.button :href="route('automation.task-runs.output', $run)" variant="secondary">{{ __('Open raw output') }}</x-signal.ui.button>
        </div>
        @if ($run->output !== null && $run->output !== '')
            <pre class="ui-console ui-console-output mt-3 max-h-[28rem] overflow-auto whitespace-pre-wrap break-words p-4" data-task-run-output-text>{{ $run->output }}</pre>
        @else
            <x-signal.ui.panel as="p" class="mt-3 bg-surface-muted p-4 text-sm text-muted">{{ __('No output was recorded.') }}</x-signal.ui.panel>
        @endif
    </div>
</div>
