@props([
    'websites',
    'destinations',
    'open' => false,
])

<x-signal.overlays.modal
    id="backup-schedule-dialog"
    :title="__('Add schedule')"
    :description="__('Automate retention without managing cron jobs.')"
    :open="$open"
>
    <form method="POST" action="{{ route('backups.schedules.store') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        <x-signal.ui.input type="hidden" name="_backup_schedule_form" value="1" :restore="false" />
        <div>
            <label class="ui-label" for="backup-schedule-website">{{ __('Website') }}</label>
            <x-signal.ui.select id="backup-schedule-website" name="website_id" class="ui-input">
                @foreach ($websites as $website)
                    <option value="{{ $website->id }}" @selected((string) old('website_id', $websites->first()->id) === (string) $website->id)>{{ $website->name }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="website_id" />
        </div>
        <div>
            <label class="ui-label" for="backup-schedule-destination">{{ __('Destination') }}</label>
            <x-signal.ui.select id="backup-schedule-destination" name="backup_destination_id" class="ui-input">
                @foreach ($destinations as $destination)
                    <option value="{{ $destination->id }}" @selected((string) old('backup_destination_id', $destinations->first()->id) === (string) $destination->id)>{{ $destination->name }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="backup_destination_id" />
        </div>
        <div>
            <label class="ui-label" for="backup-schedule-frequency">{{ __('Frequency') }}</label>
            <x-signal.ui.select id="backup-schedule-frequency" name="frequency" class="ui-input">
                <option value="daily" @selected(old('frequency', 'daily') === 'daily')>{{ __('Daily') }}</option>
                <option value="weekly" @selected(old('frequency') === 'weekly')>{{ __('Weekly') }}</option>
            </x-signal.ui.select>
            <x-forms.errors name="frequency" />
        </div>
        <div>
            <label class="ui-label" for="backup-schedule-time">{{ __('Run at (UTC)') }}</label>
            <x-signal.ui.input id="backup-schedule-time" type="time" name="run_at" value="{{ old('run_at', '02:00') }}" class="ui-input" :restore="false" />
            <x-forms.errors name="run_at" />
        </div>
        <div>
            <label class="ui-label" for="backup-schedule-weekday">{{ __('Weekday') }}</label>
            <x-signal.ui.input id="backup-schedule-weekday" type="number" name="weekday" min="0" max="6" value="{{ old('weekday', 0) }}" class="ui-input" title="{{ __('Sunday is 0') }}" :restore="false" />
            <x-forms.errors name="weekday" />
        </div>
        <div>
            <label class="ui-label" for="backup-schedule-retention">{{ __('Retention count') }}</label>
            <x-signal.ui.input id="backup-schedule-retention" type="number" name="retention_count" min="1" max="365" value="{{ old('retention_count', 14) }}" class="ui-input" :restore="false" />
            <x-forms.errors name="retention_count" />
        </div>
        <div class="sm:col-span-2">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Save schedule') }}</x-signal.ui.button>
        </div>
    </form>
</x-signal.overlays.modal>
