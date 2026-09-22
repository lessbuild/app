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
        <input type="hidden" name="_automation_dialog" value="{{ $dialogKey }}">
        <label class="block">
            <span class="ui-label">{{ __('Schedule name') }}</span>
            <input required name="name" maxlength="100" class="ui-input" placeholder="Nightly" value="{{ $formOld ? old('name') : '' }}">
            <x-forms.errors name="name" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Cron expression') }}</span>
            <input required name="cron_expression" maxlength="100" class="ui-input font-mono" value="{{ $formOld ? old('cron_expression', '0 3 * * *') : '0 3 * * *' }}">
            <x-forms.errors name="cron_expression" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Timezone') }}</span>
            <input required name="timezone" class="ui-input" value="{{ $formOld ? old('timezone', 'UTC') : 'UTC' }}">
            <x-forms.errors name="timezone" />
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Add deployment schedule') }}</x-ui.button>
    </form>
</x-dialogs.modal>
