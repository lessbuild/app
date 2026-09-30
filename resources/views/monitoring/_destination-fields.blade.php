{{-- Alert destination fields, shared by "add a destination" and a destination's settings. --}}
@php($type = $destination?->type)
<x-signal.ui.input-field name="name" :label="__('Name')" :value="$destination?->name" maxlength="120" required />
@if ($destination)
    <input type="hidden" name="type" value="{{ $destination->type->value }}">
    <input type="hidden" name="version" value="{{ $destination->state_version }}">
@else
    <x-signal.ui.select-field name="type" :label="__('Type')" required>
        @php($twilio = app(\App\Services\Monitoring\TwilioAlerts::class)->configured())
        @foreach (\App\Enums\AlertDestinationType::cases() as $option)
            @continue($option->isPhone() && ! $twilio)
            <option value="{{ $option->value }}" @selected(old('type', 'email') === $option->value)>{{ $option->label() }}</option>
        @endforeach
    </x-signal.ui.select-field>
@endif
@if (! $destination || $type?->followsPerson())
    <x-signal.ui.select-field name="recipient_user_id" :label="__('Recipient')" :description="$destination ? null : __('For email and push. A member with a verified email address, or whoever is on call in a schedule. Push reaches the devices they’ve turned on in their notification settings.')">
        <option value="">{{ __('Choose a member') }}</option>
        @php($chosen = old('recipient_user_id', $destination?->on_call_schedule_id !== null ? 'schedule:'.$destination->on_call_schedule_id : $destination?->recipient_user_id))
        @if (($schedules ?? []) !== [])
            <optgroup label="{{ __('Whoever is on call') }}">
                @foreach ($schedules as $schedule)
                    <option value="schedule:{{ $schedule->id }}" @selected($chosen === 'schedule:'.$schedule->id)>{{ __('On call: :schedule', ['schedule' => $schedule->name]) }}</option>
                @endforeach
            </optgroup>
        @endif
        <optgroup label="{{ __('A member') }}">
            @foreach ($members as $member)
                <option value="{{ $member->id }}" @selected($chosen === $member->id)>{{ $member->name }} ({{ $member->email }})</option>
            @endforeach
        </optgroup>
    </x-signal.ui.select-field>
@endif
@if (! $destination || in_array($type, [\App\Enums\AlertDestinationType::Webhook, \App\Enums\AlertDestinationType::Slack, \App\Enums\AlertDestinationType::Teams, \App\Enums\AlertDestinationType::Discord], true))
    <x-signal.ui.input-field name="endpoint_url" :label="__('Webhook URL')" type="password" autocomplete="off" maxlength="2048" :restore="false"
        :description="$destination ? __('Stored encrypted: :host. Leave blank to keep it.', ['host' => $destination->targetLabel()]) : __('For a signed webhook, Slack, Teams or Discord. A public HTTPS address on port 443.')" />
@endif
@if ((! $destination && app(\App\Services\Monitoring\TwilioAlerts::class)->configured()) || $type?->isPhone())
    <x-signal.ui.input-field name="phone_number" type="tel" :label="__('Phone number')" autocomplete="off" maxlength="16" placeholder="+447700900123" :restore="false"
        :description="$destination ? __('Stored encrypted: :number. Leave blank to keep it.', ['number' => $destination->targetLabel()]) : __('For text messages and phone calls, in international format. At most :limit a day per number.', ['limit' => config('services.twilio.daily_limit')])" />
@endif
@if (! $destination || $type === \App\Enums\AlertDestinationType::PagerDuty)
    <x-signal.ui.input-field name="signing_secret" :label="__('PagerDuty routing key')" type="password" autocomplete="off" maxlength="256" :restore="false"
        :description="$destination ? __('Leave blank to keep the stored key.') : __('For PagerDuty: the Events API v2 integration key.')" />
@endif
<x-signal.ui.checkbox name="enabled" value="1" unchecked-value="0" :checked="$destination?->enabled ?? true">{{ __('Send alerts to this destination') }}</x-signal.ui.checkbox>
