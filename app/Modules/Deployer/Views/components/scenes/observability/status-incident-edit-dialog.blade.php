@props([
    'incident',
    'open' => false,
])

@php($dialogId = 'status-incident-edit-dialog-'.$incident->id)

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Update or complete review')"
    :description="__('Publish the latest incident or maintenance status and notify subscribers when appropriate.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.incidents.update', $incident) }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PATCH')
        <x-signal.ui.input type="hidden" name="_status_incident_form" value="update" :restore="false" />
        <x-signal.ui.input type="hidden" name="_status_incident_id" value="{{ $incident->id }}" :restore="false" />
        <x-signal.ui.input type="hidden" name="kind" value="{{ $incident->kind }}" :restore="false" />
        <label class="block">
            <span class="ui-label">{{ __('Status') }}</span>
            <x-signal.ui.select name="status" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\StatusIncident::STATUSES as $status)
                    <option value="{{ $status }}" @selected(old('status', $incident->status) === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="status" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Severity') }}</span>
            <x-signal.ui.select name="severity" class="ui-input">
                @foreach (\App\Modules\Deployer\Models\StatusIncident::SEVERITIES as $severity)
                    <option value="{{ $severity }}" @selected(old('severity', $incident->severity) === $severity)>{{ ucfirst($severity) }}</option>
                @endforeach
            </x-signal.ui.select>
            <x-forms.errors name="severity" />
        </label>
        <label class="block sm:col-span-2">
            <span class="ui-label">{{ __('Title') }}</span>
            <x-signal.ui.input name="title" value="{{ old('title', $incident->title) }}" required class="ui-input" :restore="false" />
            <x-forms.errors name="title" />
        </label>
        <label class="block sm:col-span-2">
            <span class="ui-label">{{ __('Message') }}</span>
            <x-signal.ui.textarea name="message" required class="ui-input" :restore="false">{{ old('message', $incident->message) }}</x-signal.ui.textarea>
            <x-forms.errors name="message" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Root cause') }}</span>
            <x-signal.ui.textarea name="root_cause" maxlength="5000" class="ui-input" placeholder="{{ __('Root cause (internal review)') }}" :restore="false">{{ old('root_cause', $incident->root_cause) }}</x-signal.ui.textarea>
            <x-forms.errors name="root_cause" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Remediation') }}</span>
            <x-signal.ui.textarea name="remediation" maxlength="5000" class="ui-input" placeholder="{{ __('Remediation taken') }}" :restore="false">{{ old('remediation', $incident->remediation) }}</x-signal.ui.textarea>
            <x-forms.errors name="remediation" />
        </label>
        <label class="block sm:col-span-2">
            <span class="ui-label">{{ __('Follow-up') }}</span>
            <x-signal.ui.textarea name="follow_up" maxlength="5000" class="ui-input" placeholder="{{ __('Follow-up actions and owners') }}" :restore="false">{{ old('follow_up', $incident->follow_up) }}</x-signal.ui.textarea>
            <x-forms.errors name="follow_up" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Starts') }}</span>
            <x-signal.ui.input type="datetime-local" name="starts_at" value="{{ old('starts_at', $incident->starts_at->format('Y-m-d\TH:i')) }}" class="ui-input" :restore="false" />
            <x-forms.errors name="starts_at" />
        </label>
        <label class="block">
            <span class="ui-label">{{ __('Ends') }}</span>
            <x-signal.ui.input type="datetime-local" name="ends_at" value="{{ old('ends_at', $incident->ends_at?->format('Y-m-d\TH:i')) }}" class="ui-input" :restore="false" />
            <x-forms.errors name="ends_at" />
        </label>
        <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Publish update') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
