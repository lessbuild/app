@php
    $previousDays = old('deployment_window_days', $snapshot->deploymentWindowDays);
    $selectedDays = is_array($previousDays) ? array_map('strval', $previousDays) : [];
@endphp

<x-signal.layouts.platform
    :title="__('Deployment controls')"
    :description="__('Manage deployment locks, maintenance windows, rollout strategy, and automatic rollback for this mapped Deployer environment.')"
    :navigation="$navigation"
    :account-user="$accountUser"
    :current-workspace="$currentWorkspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.$project->name.' · '.$snapshot->environmentName"
        :title="__('Deployment controls')"
        :description="__('A focused Deployer settings page for this active, mapped application environment.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.show', [$workspace, $project])" variant="secondary">{{ __('Back to project') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert class="mt-5" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if ($errors->any())
        <x-signal.ui.alert class="mt-5" tone="danger" role="alert">
            <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-signal.ui.alert>
    @endif

    <x-signal.ui.card as="form" method="POST" :action="route('core.projects.deployer-deployment-controls.update', [$workspace, $project, $snapshot->environmentId])" class="mt-6 space-y-6 p-5">
        @csrf
        @method('PATCH')

        <section aria-labelledby="deployment-lock-heading" class="space-y-4">
            <div>
                <h2 id="deployment-lock-heading" class="text-base font-extrabold text-ink">{{ __('Deployment lock') }}</h2>
                <p class="mt-1 text-sm leading-6 text-muted">{{ __('Pause new deployments to this environment while the lock is active.') }}</p>
            </div>
            <label class="flex items-start gap-3 rounded-control border border-line p-4">
                <input type="hidden" name="deployment_locked" value="0">
                <input type="checkbox" name="deployment_locked" value="1" @checked((bool) old('deployment_locked', $snapshot->deploymentLocked)) class="mt-1 rounded border-line text-primary focus:ring-focus">
                <span>
                    <span class="block text-sm font-bold text-ink">{{ __('Lock deployments') }}</span>
                    <span class="mt-1 block text-sm text-muted">{{ __('The lock remains until an authorized user clears it.') }}</span>
                </span>
            </label>
            <x-signal.ui.textarea-field
                name="deployment_lock_reason"
                :label="__('Lock reason')"
                :value="old('deployment_lock_reason', $snapshot->deploymentLockReason)"
                maxlength="500"
                :description="__('Optional context shown to people who try to deploy.')"
            />
        </section>

        <section aria-labelledby="deployment-window-heading" class="space-y-4 border-t border-line pt-6">
            <div>
                <h2 id="deployment-window-heading" class="text-base font-extrabold text-ink">{{ __('Maintenance window') }}</h2>
                <p class="mt-1 text-sm leading-6 text-muted">{{ __('Restrict deployments to selected local days and times.') }}</p>
            </div>
            <label class="flex items-start gap-3 rounded-control border border-line p-4">
                <input type="hidden" name="deployment_window_enabled" value="0">
                <input type="checkbox" name="deployment_window_enabled" value="1" @checked((bool) old('deployment_window_enabled', $snapshot->deploymentWindowEnabled)) class="mt-1 rounded border-line text-primary focus:ring-focus">
                <span>
                    <span class="block text-sm font-bold text-ink">{{ __('Enable a deployment window') }}</span>
                    <span class="mt-1 block text-sm text-muted">{{ __('Deployments outside this window are blocked.') }}</span>
                </span>
            </label>
            <fieldset class="space-y-3">
                <legend class="text-sm font-bold text-ink">{{ __('Allowed days') }}</legend>
                <div class="grid gap-2 sm:grid-cols-4 lg:grid-cols-7">
                    @foreach ([1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')] as $day => $label)
                        <label class="flex items-center gap-2 rounded-control border border-line px-3 py-2 text-sm text-ink">
                            <input type="checkbox" name="deployment_window_days[]" value="{{ $day }}" @checked(in_array((string) $day, $selectedDays, true)) class="rounded border-line text-primary focus:ring-focus">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-signal.ui.input-field name="deployment_window_start" type="time" :label="__('Window starts')" :value="old('deployment_window_start', $snapshot->deploymentWindowStart)" />
                <x-signal.ui.input-field name="deployment_window_end" type="time" :label="__('Window ends')" :value="old('deployment_window_end', $snapshot->deploymentWindowEnd)" />
                <x-signal.ui.input-field name="deployment_window_timezone" :label="__('Timezone')" :value="old('deployment_window_timezone', $snapshot->deploymentWindowTimezone ?? 'UTC')" placeholder="UTC" />
            </div>
        </section>

        <section aria-labelledby="rollout-heading" class="space-y-4 border-t border-line pt-6">
            <div>
                <h2 id="rollout-heading" class="text-base font-extrabold text-ink">{{ __('Rollout and rollback') }}</h2>
                <p class="mt-1 text-sm leading-6 text-muted">{{ __('Choose the deployment strategy and the environment’s automatic rollback behavior.') }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-signal.ui.select-field name="deployment_strategy" :label="__('Deployment strategy')" required>
                    <option value="blue_green" @selected(old('deployment_strategy', $snapshot->deploymentStrategy) === 'blue_green')>{{ __('Blue green') }}</option>
                    <option value="canary" @selected(old('deployment_strategy', $snapshot->deploymentStrategy) === 'canary')>{{ __('Canary') }}</option>
                    <option value="rolling" @selected(old('deployment_strategy', $snapshot->deploymentStrategy) === 'rolling')>{{ __('Rolling') }}</option>
                </x-signal.ui.select-field>
                <x-signal.ui.select-field name="rolling_pause_seconds" :label="__('Pause between rolling steps')" required>
                    @foreach ([0, 1, 2, 5, 10, 30] as $seconds)
                        <option value="{{ $seconds }}" @selected((string) old('rolling_pause_seconds', $snapshot->rollingPauseSeconds) === (string) $seconds)>
                            {{ trans_choice(':count second|:count seconds', $seconds, ['count' => $seconds]) }}
                        </option>
                    @endforeach
                </x-signal.ui.select-field>
            </div>
            <label class="flex items-start gap-3 rounded-control border border-line p-4">
                <input type="hidden" name="automatic_rollback" value="0">
                <input type="checkbox" name="automatic_rollback" value="1" @checked((bool) old('automatic_rollback', $snapshot->automaticRollback)) class="mt-1 rounded border-line text-primary focus:ring-focus">
                <span>
                    <span class="block text-sm font-bold text-ink">{{ __('Enable automatic rollback') }}</span>
                    <span class="mt-1 block text-sm text-muted">{{ __('Restore the previous release when deployment health checks fail.') }}</span>
                </span>
            </label>
        </section>

        <div class="flex justify-end border-t border-line pt-5">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Save deployment controls') }}</x-signal.ui.button>
        </div>
    </x-signal.ui.card>
</x-signal.layouts.platform>
