@props(['open' => false])

<x-dialogs.modal
    id="alert-destination-create-dialog"
    :title="__('Add alert destination')"
    :description="__('Send signed failure and recovery events to a supported alert channel.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.destinations.store') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="_alert_destination_form" value="1">
        <h3 class="sm:col-span-2 font-bold text-primary">{{ __('Add alert destination') }}</h3>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Name') }}</span>
            <input name="name" value="{{ old('name') }}" placeholder="Engineering alerts" class="input secondary w-full rounded-md" required>
            <x-forms.errors name="name" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Type') }}</span>
            <select name="type" class="input secondary w-full rounded-md">
                @foreach (['email' => 'Email', 'discord' => 'Discord', 'teams' => 'Microsoft Teams', 'pagerduty' => 'PagerDuty', 'slack' => 'Slack', 'webhook' => __('Signed webhook')] as $value => $label)
                    <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-forms.errors name="type" />
        </label>
        <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Endpoint') }}</span>
            <input name="endpoint" value="{{ old('endpoint') }}" placeholder="{{ __('Email, webhook URL, or PagerDuty routing key') }}" autocomplete="off" class="input secondary w-full rounded-md" required>
            <x-forms.errors name="endpoint" />
        </label>
        <fieldset class="flex flex-wrap gap-4 sm:col-span-2">
            <legend class="sr-only">{{ __('Events') }}</legend>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="events[]" value="failure" @checked(in_array('failure', (array) old('events', ['failure', 'recovery']), true))>
                <span class="text-sm text-secondary">{{ __('Failures') }}</span>
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="events[]" value="recovery" @checked(in_array('recovery', (array) old('events', ['failure', 'recovery']), true))>
                <span class="text-sm text-secondary">{{ __('Recoveries') }}</span>
            </label>
            <x-forms.errors name="events" />
        </fieldset>
        <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Add destination') }}</x-ui.button>
    </form>
</x-dialogs.modal>
