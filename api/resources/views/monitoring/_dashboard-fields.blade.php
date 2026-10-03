@php($selected = collect(old('widgets', session()->hasOldInput() ? [] : ($dashboard?->widgets->pluck('type')->all() ?? array_keys(\App\Models\Dashboard::WIDGETS))))->filter(fn ($type) => is_string($type))->all())
@php($descriptions = [
    'telemetry' => __('Event volume, request duration and error rate, with a trend.'),
    'event_mix' => __('How many requests, queries, jobs, exceptions, logs and metrics arrived.'),
    'incidents' => __('Incidents from monitors and alert rules that are still open.'),
    'monitors' => __('Current health of HTTP, DNS, TLS, TCP, heartbeat and queue monitors.'),
    'objectives' => __('Compliance and error budget left for each SLO.'),
    'projects' => __('Each project and when it last sent telemetry.'),
])
{{-- A dashboard's fields: shared by the full page and the Add a dashboard modal. --}}
<div class="grid items-start gap-5 sm:grid-cols-2">
    <x-signal.ui.input-field name="name" :label="__('Name')" :value="$dashboard?->name" maxlength="120" placeholder="Production" required />
    <x-signal.ui.select-field name="range" :label="__('Telemetry range')" required>
        @foreach (\App\Queries\Telemetry\TelemetrySummaryQuery::RANGES as $value => $label)
            <option value="{{ $value }}" @selected(old('range', $dashboard?->range ?? '24h') === $value)>{{ __($label) }}</option>
        @endforeach
    </x-signal.ui.select-field>
    <div class="sm:col-span-2"><x-signal.ui.textarea-field name="description" :label="__('Description')" :value="$dashboard?->description" maxlength="1000" rows="2" /></div>
</div>
<fieldset class="grid gap-3 sm:grid-cols-2" @error('widgets') aria-describedby="widgets-error" @enderror>
    <legend class="text-sm font-bold text-ink sm:col-span-2">{{ __('Widgets') }} <span class="font-normal text-muted">{{ __('(shown in this order; pick at least one)') }}</span></legend>
    @foreach (\App\Models\Dashboard::WIDGETS as $type => $label)
        <x-signal.ui.choice :id="'widget-'.$type" name="widgets[]" :value="$type" :checked="in_array($type, $selected, true)" :restore="false" :error-key="false" :label="__($label)" :description="$descriptions[$type]" card />
    @endforeach
    <div class="sm:col-span-2"><x-signal.ui.field-error name="widgets" id="widgets-error" /></div>
</fieldset>
