{{-- The Cron jobs tab: scheduled commands, written to /etc/cron.d. --}}
<x-signal.ui.settings-section :title="__('Cron jobs')" :description="__('Commands the server runs on a schedule. Laravel apps need one running php artisan schedule:run every minute. Output goes to a log in the user’s home folder.')">
    <div class="grid gap-4 p-4 sm:p-6">
        @forelse ($cronJobs as $task)
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                <div class="min-w-0">
                    <p class="break-all font-mono text-sm text-ink">{{ $task->command }}</p>
                    <p class="text-xs text-muted">{{ $task->schedule() }} · {{ __('as :user', ['user' => $task->user]) }} · <span class="font-mono">{{ $task->frequency }}</span></p>
                    @include('infrastructure.server._task-status')
                </div>
                @include('infrastructure.server._task-actions', ['kind' => 'cron-jobs', 'editId' => 'edit-cron-'.$task->id])
                <x-signal.overlays.form-modal :id="'edit-cron-'.$task->id" :title="__('Edit cron job')" :action="route('infrastructure.servers.tasks.update', [$project, $server->id, 'cron-jobs', $task->id])" method="PUT" :submit="__('Save')" form-class="grid items-start gap-5 sm:grid-cols-2">
                    @include('infrastructure.server._cron-fields', ['job' => $task, 'prefix' => 'edit-cron-'.$task->id])
                </x-signal.overlays.form-modal>
            </div>
        @empty
            <p class="text-sm text-muted">{{ __('No cron jobs yet.') }}</p>
        @endforelse
        <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-cron'])" variant="secondary" size="sm" data-modal-trigger="add-cron">{{ __('Add a cron job') }}</x-signal.ui.button></div>
        <x-signal.overlays.form-modal id="add-cron" :title="__('Add a cron job')" :action="route('infrastructure.servers.tasks.store', [$project, $server->id, 'cron-jobs'])" :submit="__('Add cron job')" form-class="grid items-start gap-5 sm:grid-cols-2">
            @include('infrastructure.server._cron-fields', ['job' => null, 'prefix' => 'add-cron'])
        </x-signal.overlays.form-modal>
        <datalist id="cron-presets">
            @foreach (\App\Models\ServerCronJob::PRESETS as $expression => $label)<option value="{{ $expression }}">{{ __($label) }}</option>@endforeach
        </datalist>
    </div>
</x-signal.ui.settings-section>
