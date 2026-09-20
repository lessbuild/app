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
        <input type="hidden" name="_website_form" value="log-retention">
        <label>
            <span class="block text-xs font-bold uppercase text-secondary">{{ __('Snapshot retention') }}</span>
            <select name="log_retention_lines" class="input secondary mt-2 w-full rounded-lg">
                @foreach ([100, 500, 1000, 5000, 10000] as $lines)
                    <option value="{{ $lines }}" @selected((int) old('log_retention_lines', $website->log_retention_lines) === $lines)>{{ number_format($lines) }} {{ __('lines') }}</option>
                @endforeach
            </select>
            <x-forms.errors name="log_retention_lines" />
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Save retention') }}</x-ui.button>
    </form>
</x-dialogs.modal>
