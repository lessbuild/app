{{-- Maintenance window fields. Each window's form gets its own ids. --}}
@php($prefix = $window ? 'window-'.$window->id.'-' : 'window-new-')
<x-signal.ui.input-field name="name" :id="$prefix.'name'" :label="__('Name')" :value="$window?->name" maxlength="120" required />
<x-signal.ui.input-field name="reason" :id="$prefix.'reason'" :label="__('Reason')" :value="$window?->reason" maxlength="1000" />
<x-signal.ui.input-field name="starts_at" :id="$prefix.'starts'" type="datetime-local" :label="__('Starts (UTC)')" :value="$window?->starts_at->format('Y-m-d\TH:i') ?? now('UTC')->addHour()->startOfHour()->format('Y-m-d\TH:i')" required />
<x-signal.ui.input-field name="ends_at" :id="$prefix.'ends'" type="datetime-local" :label="__('Ends (UTC)')" :value="$window?->ends_at->format('Y-m-d\TH:i') ?? now('UTC')->addHours(2)->startOfHour()->format('Y-m-d\TH:i')" required />
