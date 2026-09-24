@props([
    'dialogId',
    'dialogOpen' => false,
    'dialogUrl',
    'formAction',
    'report',
    'resolved' => false,
])

@php
    $dialogTitle = $resolved ? __('Edit resolution note') : __('Resolve community report');
    $dialogDescription = $resolved
        ? __('Update the note shared with the reporter without reopening the report.')
        : __('Optionally explain what was addressed before marking this report resolved.');
    $triggerLabel = $resolved
        ? ($report->resolution_note ? __('Update Resolution Note') : __('Add Resolution Note'))
        : __('Mark Resolved');
    $submitLabel = $resolved ? $triggerLabel : __('Mark Resolved');
    $noteValue = $resolved ? old('resolution_note', $report->resolution_note) : old('resolution_note');
@endphp

<x-signal.ui.button
    href="{{ $dialogUrl }}"
    data-modal-trigger="{{ $dialogId }}"
    aria-controls="{{ $dialogId }}"
    aria-expanded="{{ $dialogOpen ? 'true' : 'false' }}"
    variant="secondary"
>
    {{ $triggerLabel }}
</x-signal.ui.button>

<x-dialogs.modal
    :id="$dialogId"
    :title="$dialogTitle"
    :description="$dialogDescription"
    :open="$dialogOpen"
>
    <form method="POST" action="{{ $formAction }}" class="space-y-3">
        @csrf
        @method('PATCH')
        <x-signal.ui.input type="hidden" name="_gallery_resolution_report_id" value="{{ $report->id }}" :restore="false" />
        <label for="{{ $dialogId }}-resolution-note" class="ui-label">
            {{ $resolved ? __('Resolution note') : __('Resolution note (optional)') }}
        </label>
        <x-signal.ui.textarea
            id="{{ $dialogId }}-resolution-note"
            name="resolution_note"
            rows="4"
            maxlength="1000"
            class="ui-input w-full"
            placeholder="{{ __('Briefly explain what was addressed.') }}" :restore="false">{{ $noteValue }}</x-signal.ui.textarea>
        @if ($resolved)
            <p class="text-xs text-muted">{{ __('Leave empty to clear the note without reopening the report.') }}</p>
        @endif
        <x-forms.errors name="resolution_note" />
        <x-signal.ui.button type="submit" variant="primary">{{ $submitLabel }}</x-signal.ui.button>
    </form>
</x-dialogs.modal>
