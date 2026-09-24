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
        <x-signal.ui.input type="hidden" name="_operational_incident_form" value="note" :restore="false" />
        <x-signal.ui.input type="hidden" name="_operational_incident_id" value="{{ $incident->id }}" :restore="false" />
        <label for="{{ $dialogId }}-message" class="ui-label">{{ __('Investigation note') }}</label>
        <x-signal.ui.textarea id="{{ $dialogId }}-message" name="message" rows="5" maxlength="5000" required class="ui-input" placeholder="{{ __('Investigation note') }}" :restore="false">{{ old('message') }}</x-signal.ui.textarea>
        <x-forms.errors name="message" />
        <x-signal.ui.button type="submit" variant="primary">{{ __('Add note') }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
