@props([
    'environment',
    'dialogKey',
    'open' => false,
])

@php
    $dialogId = 'automation-schedule-dialog-'.$environment->id;
    $formOld = old('_automation_dialog') === $dialogKey;
@endphp

<x-dialogs.modal
    id="{{ $dialogId }}"
    :title="__('Add deployment schedule')"
    :description="__('Choose when this environment should receive an automatic deployment.')"
    :open="$open"
>
    <form method="POST" action="{{ route('automation.deployment-schedules.store', $environment) }}" class="space-y-4">
        @csrf
        <x-signal.ui.input type="hidden" name="_automation_dialog" value="{{ $dialogKey }}" :restore="false" />
        <x-signal.ui.input-field
            :id="$dialogId.'-name'"
            name="name"
            :label="__('Schedule name')"
            :value="$formOld ? old('name') : ''"
            maxlength="100"
            required
            placeholder="Nightly"
            :restore="false"
        />
        <x-signal.ui.input-field
            :id="$dialogId.'-cron-expression'"
            name="cron_expression"
            :label="__('Cron expression')"
            :value="$formOld ? old('cron_expression', '0 3 * * *') : '0 3 * * *'"
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
        <x-signal.ui.button type="submit" variant="primary">{{ __('Add deployment schedule') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
