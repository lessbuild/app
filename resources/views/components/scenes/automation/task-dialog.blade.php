@props([
    'environment',
    'dialogKey',
    'open' => false,
])

@php
    $dialogId = 'automation-task-dialog-'.$environment->id;
    $formOld = old('_automation_dialog') === $dialogKey;
@endphp

<x-dialogs.modal
    id="{{ $dialogId }}"
    :title="__('Add scheduled task')"
    :description="__('Run a bounded command on a recurring schedule for this environment.')"
    :open="$open"
>
    <form method="POST" action="{{ route('automation.tasks.store', $environment) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_automation_dialog" value="{{ $dialogKey }}">
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Task name') }}</span>
            <input name="name" maxlength="100" required class="input secondary w-full rounded-md" placeholder="Warm cache" value="{{ $formOld ? old('name') : '' }}">
            <x-forms.errors name="name" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Cron expression') }}</span>
            <input name="cron_expression" maxlength="100" required class="input secondary w-full rounded-md font-mono" value="{{ $formOld ? old('cron_expression', '0 * * * *') : '0 * * * *' }}">
            <x-forms.errors name="cron_expression" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Timezone') }}</span>
            <input name="timezone" required class="input secondary w-full rounded-md" value="{{ $formOld ? old('timezone', 'UTC') : 'UTC' }}">
            <x-forms.errors name="timezone" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Command') }}</span>
            <textarea name="command" maxlength="4000" required class="input secondary w-full rounded-md font-mono" rows="3" placeholder="php artisan cache:warm">{{ $formOld ? old('command') : '' }}</textarea>
            <x-forms.errors name="command" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Timeout seconds') }}</span>
            <input type="number" name="timeout_seconds" min="10" max="3600" value="{{ $formOld ? old('timeout_seconds', 300) : 300 }}" required class="input secondary w-full rounded-md" aria-describedby="task-timeout-help-{{ $environment->id }}">
            <span id="task-timeout-help-{{ $environment->id }}" class="mt-1 block text-xs text-secondary">{{ __('Between 10 seconds and 1 hour.') }}</span>
            <x-forms.errors name="timeout_seconds" />
        </label>
        <div class="flex flex-wrap gap-4 text-sm text-secondary">
            <input type="hidden" name="without_overlapping" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="without_overlapping" value="1" @checked(! $formOld || (string) old('without_overlapping') === '1')>{{ __('Prevent overlap') }}</label>
            <input type="hidden" name="alert_on_failure" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="alert_on_failure" value="1" @checked(! $formOld || (string) old('alert_on_failure') === '1')>{{ __('Alert on failure') }}</label>
        </div>
        <x-ui.button type="submit" variant="primary">{{ __('Add scheduled task') }}</x-ui.button>
    </form>
</x-dialogs.modal>
