@php
    $statusTone = match ((string) $run->status) {
        'succeeded' => 'success',
        'failed' => 'danger',
        'canceled' => 'warning',
        default => 'accent',
    };
@endphp

<div data-task-run-output class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Scheduled task') }}</p>
            <h3 class="mt-1 text-xl font-black text-primary">{{ $task->name }}</h3>
            <p class="mt-1 text-sm text-secondary">{{ $task->environment->project->name }} · {{ $task->environment->name }}</p>
        </div>
        <x-ui.badge :tone="$statusTone">{{ $run->status }}</x-ui.badge>
    </div>

    <dl class="grid gap-3 sm:grid-cols-3">
        <x-ui.stat class="ui-card" :label="__('Queued')" :value="$run->created_at?->diffForHumans() ?? __('Not recorded')" :description="__('When this run was queued.')" />
        <x-ui.stat class="ui-card" :label="__('Finished')" :value="$run->finished_at?->diffForHumans() ?? __('Not finished')" :description="__('When the remote command completed.')" />
        <x-ui.stat class="ui-card" :label="__('Duration')" :value="$run->duration_ms !== null ? number_format($run->duration_ms).' ms' : __('Not recorded')" :description="__('Recorded remote execution time.')" />
    </dl>

    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h4 class="font-bold text-primary">{{ __('Retained output') }}</h4>
                <p class="mt-1 text-xs text-secondary">{{ __('Output is encrypted at rest and shown only to authorized workspace members.') }}</p>
            </div>
            <x-ui.button :href="route('automation.task-runs.output', $run)" variant="secondary">{{ __('Open raw output') }}</x-ui.button>
        </div>
        @if ($run->output !== null && $run->output !== '')
            <pre class="mt-3 max-h-[28rem] overflow-auto whitespace-pre-wrap break-words rounded-xl bg-gray-950 p-4 font-mono text-xs leading-5 text-gray-100" data-task-run-output-text>{{ $run->output }}</pre>
        @else
            <p class="mt-3 rounded-xl border border-primary bg-secondary p-4 text-sm text-secondary">{{ __('No output was recorded.') }}</p>
        @endif
    </div>
</div>
