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
        <input type="hidden" name="_status_incident_form" value="update">
        <input type="hidden" name="_status_incident_id" value="{{ $incident->id }}">
        <input type="hidden" name="kind" value="{{ $incident->kind }}">
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Status') }}</span>
            <select name="status" class="input secondary w-full rounded-md">
                @foreach (\App\Models\StatusIncident::STATUSES as $status)
                    <option value="{{ $status }}" @selected(old('status', $incident->status) === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
            <x-forms.errors name="status" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Severity') }}</span>
            <select name="severity" class="input secondary w-full rounded-md">
                @foreach (\App\Models\StatusIncident::SEVERITIES as $severity)
                    <option value="{{ $severity }}" @selected(old('severity', $incident->severity) === $severity)>{{ ucfirst($severity) }}</option>
                @endforeach
            </select>
            <x-forms.errors name="severity" />
        </label>
        <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Title') }}</span>
            <input name="title" value="{{ old('title', $incident->title) }}" required class="input secondary w-full rounded-md">
            <x-forms.errors name="title" />
        </label>
        <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Message') }}</span>
            <textarea name="message" required class="input secondary w-full rounded-md">{{ old('message', $incident->message) }}</textarea>
            <x-forms.errors name="message" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Root cause') }}</span>
            <textarea name="root_cause" maxlength="5000" class="input secondary w-full rounded-md" placeholder="{{ __('Root cause (internal review)') }}">{{ old('root_cause', $incident->root_cause) }}</textarea>
            <x-forms.errors name="root_cause" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Remediation') }}</span>
            <textarea name="remediation" maxlength="5000" class="input secondary w-full rounded-md" placeholder="{{ __('Remediation taken') }}">{{ old('remediation', $incident->remediation) }}</textarea>
            <x-forms.errors name="remediation" />
        </label>
        <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Follow-up') }}</span>
            <textarea name="follow_up" maxlength="5000" class="input secondary w-full rounded-md" placeholder="{{ __('Follow-up actions and owners') }}">{{ old('follow_up', $incident->follow_up) }}</textarea>
            <x-forms.errors name="follow_up" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Starts') }}</span>
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $incident->starts_at->format('Y-m-d\TH:i')) }}" class="input secondary w-full rounded-md">
            <x-forms.errors name="starts_at" />
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Ends') }}</span>
            <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $incident->ends_at?->format('Y-m-d\TH:i')) }}" class="input secondary w-full rounded-md">
            <x-forms.errors name="ends_at" />
        </label>
        <x-ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Publish update') }}</x-ui.button>
    </form>
</x-dialogs.modal>
