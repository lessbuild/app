@props([
    'websites',
    'destinations',
    'open' => false,
])

<x-dialogs.modal
    id="backup-schedule-dialog"
    :title="__('Add schedule')"
    :description="__('Automate retention without managing cron jobs.')"
    :open="$open"
>
    <form method="POST" action="{{ route('backups.schedules.store') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="_backup_schedule_form" value="1">
        <label class="block" for="backup-schedule-website">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Website') }}</span>
            <select id="backup-schedule-website" name="website_id" class="input secondary w-full rounded-md">
                @foreach ($websites as $website)
                    <option value="{{ $website->id }}" @selected((string) old('website_id', $websites->first()->id) === (string) $website->id)>{{ $website->name }}</option>
                @endforeach
            </select>
            <x-forms.errors name="website_id" />
        </label>
        <label class="block" for="backup-schedule-destination">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Destination') }}</span>
            <select id="backup-schedule-destination" name="backup_destination_id" class="input secondary w-full rounded-md">
                @foreach ($destinations as $destination)
                    <option value="{{ $destination->id }}" @selected((string) old('backup_destination_id', $destinations->first()->id) === (string) $destination->id)>{{ $destination->name }}</option>
                @endforeach
            </select>
            <x-forms.errors name="backup_destination_id" />
        </label>
        <label class="block" for="backup-schedule-frequency">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Frequency') }}</span>
            <select id="backup-schedule-frequency" name="frequency" class="input secondary w-full rounded-md">
                <option value="daily" @selected(old('frequency', 'daily') === 'daily')>{{ __('Daily') }}</option>
                <option value="weekly" @selected(old('frequency') === 'weekly')>{{ __('Weekly') }}</option>
            </select>
            <x-forms.errors name="frequency" />
        </label>
        <label class="block" for="backup-schedule-time">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Run at (UTC)') }}</span>
            <input id="backup-schedule-time" type="time" name="run_at" value="{{ old('run_at', '02:00') }}" class="input secondary w-full rounded-md">
            <x-forms.errors name="run_at" />
        </label>
        <label class="block" for="backup-schedule-weekday">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Weekday') }}</span>
            <input id="backup-schedule-weekday" type="number" name="weekday" min="0" max="6" value="{{ old('weekday', 0) }}" class="input secondary w-full rounded-md" title="{{ __('Sunday is 0') }}">
            <x-forms.errors name="weekday" />
        </label>
        <label class="block" for="backup-schedule-retention">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Retention count') }}</span>
            <input id="backup-schedule-retention" type="number" name="retention_count" min="1" max="365" value="{{ old('retention_count', 14) }}" class="input secondary w-full rounded-md">
            <x-forms.errors name="retention_count" />
        </label>
        <div class="sm:col-span-2">
            <x-ui.button type="submit" variant="primary">{{ __('Save schedule') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
