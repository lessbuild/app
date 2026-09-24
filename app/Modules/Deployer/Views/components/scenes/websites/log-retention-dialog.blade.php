@props([
    'website',
    'open' => false,
])

<x-dialogs.modal
    id="website-log-retention-dialog"
    :title="__('Log retention settings')"
    :description="__('Choose how many lines future application and access log snapshots retain.')"
    :open="$open"
>
    <form method="POST" action="{{ route('websites.runtime-logs.retention', ['website' => $website, 'dialog' => 'website-log-retention']) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <x-signal.ui.input type="hidden" name="_website_form" value="log-retention" :restore="false" />
        <label>
            <span class="ui-label">{{ __('Snapshot retention') }}</span>
            <x-signal.ui.select name="log_retention_lines" class="ui-input mt-2 w-full">
                @foreach ([100, 500, 1000, 5000, 10000] as $lines)
                    <option value="{{ $lines }}" @selected((int) old('log_retention_lines', $website->log_retention_lines) === $lines)>{{ number_format($lines) }} {{ __('lines') }}</option>
                @endforeach
            </x-signal.ui.select>
            <p class="ui-help">{{ __('Only future snapshots use this limit; existing retained output is unchanged.') }}</p>
            <x-forms.errors name="log_retention_lines" />
        </label>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save retention') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
