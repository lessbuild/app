@php
    $dialogId = $dialogId ?? 'gallery-report-resolution-'.$report->id;
    $dialogOpen = $dialogOpen ?? false;
    $resolved = $resolved ?? $report->resolved_at !== null;
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

<x-ui.button
    href="{{ $dialogUrl }}"
    data-modal-trigger="{{ $dialogId }}"
    aria-controls="{{ $dialogId }}"
    aria-expanded="{{ $dialogOpen ? 'true' : 'false' }}"
    variant="secondary"
>
    {{ $triggerLabel }}
</x-ui.button>

<x-dialogs.modal
    :id="$dialogId"
    :title="$dialogTitle"
    :description="$dialogDescription"
    :open="$dialogOpen"
>
    <form method="POST" action="{{ $formAction }}" class="space-y-3">
        @csrf
        @method('PATCH')
        <input type="hidden" name="_gallery_resolution_report_id" value="{{ $report->id }}">
        <label for="{{ $dialogId }}-resolution-note" class="block text-xs font-semibold uppercase text-secondary">
            {{ $resolved ? __('Resolution note') : __('Resolution note (optional)') }}
        </label>
        <textarea
            id="{{ $dialogId }}-resolution-note"
            name="resolution_note"
            rows="4"
            maxlength="1000"
            class="input secondary w-full rounded-lg"
            placeholder="{{ __('Briefly explain what was addressed.') }}"
        >{{ $noteValue }}</textarea>
        @if ($resolved)
            <p class="text-xs text-secondary">{{ __('Leave empty to clear the note without reopening the report.') }}</p>
        @endif
        <x-forms.errors name="resolution_note" />
        <x-ui.button type="submit" variant="primary">{{ $submitLabel }}</x-ui.button>
    </form>
</x-dialogs.modal>
