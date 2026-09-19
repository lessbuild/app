@props([
    'incident',
    'open' => false,
])

@php($dialogId = 'operational-incident-note-'.$incident->id)

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Add investigation note')"
    :description="__('Record bounded evidence in the incident timeline without changing its status.')"
    :open="$open"
>
    <form method="POST" action="{{ route('observability.operational-incidents.notes.store', $incident) }}" class="space-y-3">
        @csrf
        <input type="hidden" name="_operational_incident_form" value="note">
        <input type="hidden" name="_operational_incident_id" value="{{ $incident->id }}">
        <label for="{{ $dialogId }}-message" class="block text-xs font-semibold uppercase text-secondary">{{ __('Investigation note') }}</label>
        <textarea id="{{ $dialogId }}-message" name="message" rows="5" maxlength="5000" required class="input secondary w-full rounded-md" placeholder="{{ __('Investigation note') }}">{{ old('message') }}</textarea>
        <x-forms.errors name="message" />
        <x-ui.button type="submit" variant="primary">{{ __('Add note') }}</x-ui.button>
    </form>
</x-dialogs.modal>
