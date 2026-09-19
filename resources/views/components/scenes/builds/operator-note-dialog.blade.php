@props([
    'build',
    'open' => false,
])

<x-dialogs.modal
    id="build-note-dialog"
    :title="$build->operator_note ? __('Edit operator note') : __('Add operator note')"
    :description="__('Keep incident, approval and handoff context here. Do not store secrets.')"
    :open="$open"
    wire:ignore
>
    <form method="POST" action="{{ route('builds.note.update', $build) }}" class="space-y-4">
        @csrf
        @method('PATCH')
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Operator note') }}</span>
            <textarea
                name="operator_note"
                rows="6"
                maxlength="2000"
                class="input secondary w-full rounded-lg"
                placeholder="{{ __('Example: Approved rollback for incident INC-1042.') }}"
            >{{ old('operator_note', $build->operator_note) }}</textarea>
        </label>
        <x-forms.errors name="operator_note" bag="buildNote" />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-secondary">{{ __('Remove all text and save to clear the note.') }}</p>
            <x-ui.button type="submit" variant="primary">{{ __('Save note') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
