{{-- Alert destination fields, shared by "add a destination" and a destination's settings. --}}
@php($type = $destination?->type)
<x-signal.ui.input-field name="name" :label="__('Name')" :value="$destination?->name" maxlength="120" required />
@if ($destination)
    <input type="hidden" name="type" value="{{ $destination->type->value }}">
    <input type="hidden" name="version" value="{{ $destination->state_version }}">
@else
    <x-signal.ui.select-field name="type" :label="__('Type')" required>
        @foreach (\App\Enums\AlertDestinationType::cases() as $option)
            <option value="{{ $option->value }}" @selected(old('type', 'email') === $option->value)>{{ $option->label() }}</option>
        @endforeach
    </x-signal.ui.select-field>
@endif
@if (! $destination || $type === \App\Enums\AlertDestinationType::Email)
    <x-signal.ui.select-field name="recipient_user_id" :label="__('Email recipient')" :description="$destination ? null : __('For email. Members with a verified email address.')">
        <option value="">{{ __('Choose a member') }}</option>
        @foreach ($members as $member)
            <option value="{{ $member->id }}" @selected(old('recipient_user_id', $destination?->recipient_user_id) === $member->id)>{{ $member->name }} ({{ $member->email }})</option>
        @endforeach
    </x-signal.ui.select-field>
@endif
@if (! $destination || in_array($type, [\App\Enums\AlertDestinationType::Webhook, \App\Enums\AlertDestinationType::Slack, \App\Enums\AlertDestinationType::Teams, \App\Enums\AlertDestinationType::Discord], true))
    <x-signal.ui.input-field name="endpoint_url" :label="__('Webhook URL')" type="password" autocomplete="off" maxlength="2048" :restore="false"
        :description="$destination ? __('Stored encrypted: :host. Leave blank to keep it.', ['host' => $destination->targetLabel()]) : __('For a signed webhook, Slack, Teams or Discord. A public HTTPS address on port 443.')" />
@endif
@if (! $destination || $type === \App\Enums\AlertDestinationType::PagerDuty)
    <x-signal.ui.input-field name="signing_secret" :label="__('PagerDuty routing key')" type="password" autocomplete="off" maxlength="256" :restore="false"
        :description="$destination ? __('Leave blank to keep the stored key.') : __('For PagerDuty: the Events API v2 integration key.')" />
@endif
<x-signal.ui.checkbox name="enabled" value="1" unchecked-value="0" :checked="$destination?->enabled ?? true">{{ __('Send alerts to this destination') }}</x-signal.ui.checkbox>
