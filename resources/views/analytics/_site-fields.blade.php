{{-- Site form fields, shared by "add a site" and a site's settings. --}}
@php($domainLines = $site ? implode(PHP_EOL, $site->domains) : null)
@php($excludedLines = $site ? implode(PHP_EOL, $site->excluded_paths ?? []) : null)
<x-signal.ui.input-field name="name" :label="__('Site name')" :value="$site?->name" maxlength="100" required />
<x-signal.ui.textarea-field name="domains" :label="__('Hostnames')" :value="$domainLines" :description="__('One per line, such as example.com and www.example.com. The tracker only accepts data sent from these.')" rows="2" required />
<x-signal.ui.select-field name="timezone" :label="__('Report time zone')" required>
    @foreach ($timezones as $timezone)
        <option value="{{ $timezone }}" @selected(old('timezone', $site?->timezone ?? 'UTC') === $timezone)>{{ $timezone }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.textarea-field name="excluded_paths" :label="__('Paths to ignore')" :value="$excludedLines" :description="__('Optional. One pattern per line; * matches anything, e.g. /admin/*.')" rows="2" />
