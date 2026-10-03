{{-- The Processes tab: long-running commands Supervisor keeps alive. --}}
<x-signal.ui.settings-section :title="__('Processes')" :description="__('Commands that should always be running, such as queue workers, Horizon or Reverb. Supervisor starts them, restarts them if they stop, and gives them time to finish their work when stopped.')">
    <div class="grid gap-4 p-4 sm:p-6">
        @forelse ($processes as $task)
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                <div class="min-w-0">
                    <p class="font-bold text-ink">{{ $task->name }}</p>
                    <p class="break-all font-mono text-xs text-muted">{{ $task->command }}</p>
                    <p class="text-xs text-muted">{{ trans_choice(':count copy|:count copies', $task->processes, ['count' => $task->processes]) }} · {{ __('as :user', ['user' => $task->user]) }}@if ($task->directory) · {{ $task->directory }}@endif</p>
                    @include('infrastructure.server._task-status')
                </div>
                <span class="flex shrink-0 items-center gap-2">
                    @if ($task->status === 'active')
                        <form method="POST" action="{{ route('infrastructure.servers.processes.restart', [$project, $server->id, $task->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Restart') }}</x-signal.ui.button></form>
                    @endif
                    @include('infrastructure.server._task-actions', ['kind' => 'processes', 'editId' => 'edit-process-'.$task->id])
                </span>
                <x-signal.overlays.form-modal :id="'edit-process-'.$task->id" :title="__('Edit process')" :action="route('infrastructure.servers.tasks.update', [$project, $server->id, 'processes', $task->id])" method="PUT" :submit="__('Save')" form-class="grid items-start gap-5 sm:grid-cols-2">
                    @include('infrastructure.server._process-fields', ['process' => $task, 'prefix' => 'edit-process-'.$task->id])
                </x-signal.overlays.form-modal>
            </div>
        @empty
            <p class="text-sm text-muted">{{ __('No processes yet.') }}</p>
        @endforelse
        <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-process'])" variant="secondary" size="sm" data-modal-trigger="add-process">{{ __('Add a process') }}</x-signal.ui.button></div>
        <x-signal.overlays.form-modal id="add-process" :title="__('Add a process')" :action="route('infrastructure.servers.tasks.store', [$project, $server->id, 'processes'])" :submit="__('Add process')" form-class="grid items-start gap-5 sm:grid-cols-2">
            <nav class="flex flex-wrap gap-2 sm:col-span-2" aria-label="{{ __('Start from') }}">
                @foreach (\App\Models\ServerProcess::PRESETS as $key => $preset)
                    <x-signal.ui.button :href="route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'processes', 'preset' => $key, 'dialog' => 'add-process'])" variant="secondary" size="sm">{{ __($preset['name']) }}</x-signal.ui.button>
                @endforeach
            </nav>
            @include('infrastructure.server._process-fields', ['process' => $processPreset !== null ? (new \App\Models\ServerProcess)->forceFill($processPreset) : null, 'prefix' => 'add-process'])
        </x-signal.overlays.form-modal>
    </div>
</x-signal.ui.settings-section>
