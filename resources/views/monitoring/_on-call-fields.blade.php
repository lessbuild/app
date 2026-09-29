{{-- An on-call schedule's fields; $schedule is null for a new one. Members take turns in the order of the numbered slots. --}}
@php($weekdays = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')])
@php($current = $schedule?->members->pluck('id')->all() ?? [])
<div class="sm:col-span-2"><x-signal.ui.input-field :id="$prefix.'-name'" name="name" :label="__('Name')" :value="$schedule?->name" placeholder="Primary" maxlength="120" required /></div>
<x-signal.ui.select-field :id="$prefix.'-rotation'" name="rotation" :label="__('Each turn lasts')">
    <option value="weekly" @selected(($schedule?->rotation ?? 'weekly') === 'weekly')>{{ __('A week') }}</option>
    <option value="daily" @selected($schedule?->rotation === 'daily')>{{ __('A day') }}</option>
</x-signal.ui.select-field>
<x-signal.ui.select-field :id="$prefix.'-day'" name="handoff_day" :label="__('Hand over on (weekly)')">
    @foreach ($weekdays as $number => $label)
        <option value="{{ $number }}" @selected(($schedule?->handoff_day ?? 1) === $number)>{{ $label }}</option>
    @endforeach
</x-signal.ui.select-field>
<x-signal.ui.input-field :id="$prefix.'-time'" name="handoff_time" type="time" :label="__('Hand over at')" :value="$schedule?->handoff_time ?? '09:00'" required />
<x-signal.ui.input-field :id="$prefix.'-timezone'" name="timezone" :label="__('Time zone')" :value="$schedule?->timezone ?? 'UTC'" maxlength="64" required />
<div class="sm:col-span-2"><x-signal.ui.input-field :id="$prefix.'-starts'" name="starts_on" type="date" :label="__('First turn starts on')" :value="$schedule?->starts_on->format('Y-m-d') ?? now()->format('Y-m-d')" required /></div>
<fieldset class="grid gap-3 sm:col-span-2 sm:grid-cols-2">
    <legend class="mb-2 text-sm font-bold text-ink">{{ __('Who takes turns, in order') }}</legend>
    @for ($slot = 0; $slot < min(10, max(1, count($members))); $slot++)
        <x-signal.ui.select-field :id="$prefix.'-member-'.$slot" name="member_ids[]" :label="__('Turn :number', ['number' => $slot + 1])">
            <option value="">{{ __('No one') }}</option>
            @foreach ($members as $member)
                <option value="{{ $member->id }}" @selected(($current[$slot] ?? null) === $member->id)>{{ $member->name }}</option>
            @endforeach
        </x-signal.ui.select-field>
    @endfor
</fieldset>
