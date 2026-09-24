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
        <x-signal.ui.input-field
            :id="$dialogId.'-name'"
            name="name"
            :label="__('Task name')"
            :value="$formOld ? old('name') : ''"
            maxlength="100"
            required
            placeholder="Warm cache"
            :restore="false"
        />
        <x-signal.ui.input-field
            :id="$dialogId.'-cron-expression'"
            name="cron_expression"
            :label="__('Cron expression')"
            :value="$formOld ? old('cron_expression', '0 * * * *') : '0 * * * *'"
            maxlength="100"
            required
            :restore="false"
            class="font-mono"
        />
        <x-signal.ui.input-field
            :id="$dialogId.'-timezone'"
            name="timezone"
            :label="__('Timezone')"
            :value="$formOld ? old('timezone', 'UTC') : 'UTC'"
            required
            :restore="false"
        />
        <x-signal.ui.textarea-field
            :id="$dialogId.'-command'"
            name="command"
            :label="__('Command')"
            :value="$formOld ? old('command') : ''"
            maxlength="4000"
            required
            rows="3"
            placeholder="php artisan cache:warm"
            :restore="false"
            class="font-mono"
        />
        <x-signal.ui.input-field
            :id="$dialogId.'-timeout-seconds'"
            name="timeout_seconds"
            :label="__('Timeout seconds')"
            :description="__('Between 10 seconds and 1 hour.')"
            type="number"
            min="10"
            max="3600"
            :value="$formOld ? old('timeout_seconds', 300) : 300"
            required
            :restore="false"
        />
        <div class="grid gap-2 sm:grid-cols-2">
            <x-signal.ui.checkbox
                :id="$dialogId.'-without-overlapping'"
                name="without_overlapping"
                value="1"
                :checked="! $formOld || (string) old('without_overlapping') === '1'"
                unchecked-value="0"
                :restore="false"
            >
                {{ __('Prevent overlap') }}
            </x-signal.ui.checkbox>
            <x-signal.ui.checkbox
                :id="$dialogId.'-alert-on-failure'"
                name="alert_on_failure"
                value="1"
                :checked="! $formOld || (string) old('alert_on_failure') === '1'"
                unchecked-value="0"
                :restore="false"
            >
                {{ __('Alert on failure') }}
            </x-signal.ui.checkbox>
        </div>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Add scheduled task') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
