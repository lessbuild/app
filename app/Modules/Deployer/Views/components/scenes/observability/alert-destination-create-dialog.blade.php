@props(['open' => false])

<x-signal.overlays.modal
    id="alert-destination-create-dialog"
    :title="__('Add alert destination')"
    :description="__('Send signed failure and recovery events to a supported alert channel.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.destinations.store') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        <x-signal.ui.input type="hidden" name="_alert_destination_form" value="1" :restore="false" />
        <h3 class="sm:col-span-2 font-bold text-ink">{{ __('Add alert destination') }}</h3>
        <label class="block">
            <span class="ui-label">{{ __('Name') }}</span>
            <x-signal.ui.input name="name" value="{{ old('name') }}" placeholder="Engineering alerts" class="ui-input" required :restore="false" />
            <x-forms.errors name="name" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Type') }}</span>
            <x-signal.ui.select name="type" class="ui-input">
                @foreach (['email' => 'Email', 'discord' => 'Discord', 'teams' => 'Microsoft Teams', 'pagerduty' => 'PagerDuty', 'slack' => 'Slack', 'webhook' => __('Signed webhook')] as $value => $label)
                    <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="type" />
        </label>
        <label class="block sm:col-span-2">
            <span class="ui-label">{{ __('Endpoint') }}</span>
            <x-signal.ui.input name="endpoint" value="{{ old('endpoint') }}" placeholder="{{ __('Email, webhook URL, or PagerDuty routing key') }}" autocomplete="off" class="ui-input" required :restore="false" />
            <x-forms.errors name="endpoint" />
        </label>
        <fieldset class="flex flex-wrap gap-4 sm:col-span-2">
            <legend class="sr-only">{{ __('Events') }}</legend>
            <label class="flex items-center gap-2">
                <x-signal.ui.input type="checkbox" name="events[]" value="failure" class="ui-check" @checked(in_array('failure', (array) old('events', ['failure', 'recovery']), true)) :restore="false" />
                <span class="text-sm text-muted">{{ __('Failures') }}</span>
            </label>
            <label class="flex items-center gap-2">
                <x-signal.ui.input type="checkbox" name="events[]" value="recovery" class="ui-check" @checked(in_array('recovery', (array) old('events', ['failure', 'recovery']), true)) :restore="false" />
                <span class="text-sm text-muted">{{ __('Recoveries') }}</span>
            </label>
            <x-forms.errors name="events" />
        </fieldset>
        <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Add destination') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
