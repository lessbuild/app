@props([
    'environment',
    'open' => false,
])

@php($dialogId = 'environment-process-dialog-'.$environment->id)

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Add worker or scheduler')"
    :description="__('Define an encrypted process command for the next deployment.')"
    :open="$open"
>
    <form method="POST" action="{{ route('environments.processes.store', $environment) }}" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="_environment_id" value="{{ $environment->id }}">
        <input type="hidden" name="_environment_panel" value="processes">
        <label>
            <span class="ui-label">{{ __('Name') }}</span>
            <input name="name" value="{{ old('name') }}" placeholder="queue" class="ui-input" required>
            <x-forms.errors name="name" />
        </label>
        <label>
            <span class="ui-label">{{ __('Type') }}</span>
            <select name="type" class="ui-input">
                <option value="worker" @selected(old('type', 'worker') === 'worker')>{{ __('Worker') }}</option>
                <option value="scheduler" @selected(old('type') === 'scheduler')>{{ __('Scheduler') }}</option>
            </select>
            <x-forms.errors name="type" />
        </label>
        <label class="sm:col-span-2">
            <span class="ui-label">{{ __('Command') }}</span>
            <input name="command" value="{{ old('command') }}" placeholder="php artisan queue:work" class="ui-input font-mono" required>
            <x-forms.errors name="command" />
        </label>
        <label>
            <span class="ui-label">{{ __('Replicas') }}</span>
            <input type="number" name="replicas" min="1" max="20" value="{{ old('replicas', 1) }}" class="ui-input" required>
            <x-forms.errors name="replicas" />
        </label>
        <label>
            <span class="ui-label">{{ __('Restart policy') }}</span>
            <select name="restart_policy" class="ui-input">
                <option value="always" @selected(old('restart_policy', 'always') === 'always')>{{ __('Always restart') }}</option>
                <option value="on-failure" @selected(old('restart_policy') === 'on-failure')>{{ __('Restart on failure') }}</option>
                <option value="no" @selected(old('restart_policy') === 'no')>{{ __('Never restart') }}</option>
            </select>
            <x-forms.errors name="restart_policy" />
        </label>
        <label>
            <span class="ui-label">{{ __('Restart delay seconds') }}</span>
            <input type="number" name="restart_delay_seconds" min="0" max="300" value="{{ old('restart_delay_seconds', 5) }}" class="ui-input" aria-label="{{ __('Restart delay seconds') }}" required>
            <x-forms.errors name="restart_delay_seconds" />
        </label>
        <input type="hidden" name="is_enabled" value="1">
        <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save process') }}</x-ui.button>
    </form>
</x-dialogs.modal>
