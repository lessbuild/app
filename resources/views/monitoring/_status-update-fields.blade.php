{{-- Status update fields. Each update's form gets its own ids. Times are UTC. --}}
@php($prefix = $update ? 'update-'.$update->id.'-' : 'update-new-')
@php($kind = $update?->kind ?? old('kind', 'incident'))
@php($currentStatus = $update?->status ?? old('status', 'investigating'))
@php($severity = $update?->severity ?? old('severity', 'minor'))
<x-signal.ui.select-field name="kind" :id="$prefix.'kind'" :label="__('Type')" required>
    <option value="incident" @selected($kind === 'incident')>{{ __('Incident') }}</option>
    <option value="maintenance" @selected($kind === 'maintenance')>{{ __('Maintenance') }}</option>
</x-signal.ui.select-field>
<x-signal.ui.select-field name="status" :id="$prefix.'status'" :label="__('Status')" :description="__('Incidents: investigating → resolved. Maintenance: scheduled → completed.')" required>
    @foreach (\App\Models\StatusUpdate::STATUSES as $group => $statuses)
        <optgroup label="{{ $group === 'incident' ? __('Incident') : __('Maintenance') }}">
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($currentStatus === $status)>{{ __(str_replace('_', ' ', ucfirst($status))) }}</option>
            @endforeach
        </optgroup>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.select-field name="severity" :id="$prefix.'severity'" :label="__('Impact')" required>
    @foreach (['minor' => __('Minor'), 'major' => __('Major'), 'critical' => __('Critical: shows as a major outage')] as $value => $label)
        <option value="{{ $value }}" @selected($severity === $value)>{{ $label }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field name="title" :id="$prefix.'title'" :label="__('Title')" :value="$update?->title" :restore="$update === null" maxlength="255" required />
<div class="sm:col-span-2">
    <x-signal.ui.textarea-field name="message" :id="$prefix.'message'" :label="__('Message')" :value="$update?->message" :restore="$update === null" maxlength="5000" rows="3" required />
</div>
<x-signal.ui.input-field name="starts_at" :id="$prefix.'starts'" type="datetime-local" :label="__('Starts (UTC)')" :value="$update?->starts_at->format('Y-m-d\TH:i') ?? now('UTC')->format('Y-m-d\TH:i')" :restore="$update === null" required />
<x-signal.ui.input-field name="ends_at" :id="$prefix.'ends'" type="datetime-local" :label="__('Ends (UTC)')" :value="$update?->ends_at?->format('Y-m-d\TH:i')" :restore="$update === null" :description="__('Optional. For maintenance, when it should be over.')" />
<details class="sm:col-span-2" @if ($update?->root_cause || $update?->remediation || $update?->follow_up) open @endif>
    <summary class="cursor-pointer text-sm font-bold text-primary">{{ __('Incident review (optional)') }}</summary>
    <div class="mt-3 grid gap-4">
        <x-signal.ui.textarea-field name="root_cause" :id="$prefix.'root-cause'" :label="__('What happened')" :value="$update?->root_cause" :restore="$update === null" maxlength="5000" rows="2" />
        <x-signal.ui.textarea-field name="remediation" :id="$prefix.'remediation'" :label="__('What we did')" :value="$update?->remediation" :restore="$update === null" maxlength="5000" rows="2" />
        <x-signal.ui.textarea-field name="follow_up" :id="$prefix.'follow-up'" :label="__('What’s next')" :value="$update?->follow_up" :restore="$update === null" maxlength="5000" rows="2" />
    </div>
</details>
