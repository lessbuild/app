@props([
    'action',
    'open' => false,
])

<x-signal.overlays.modal
    id="notification-save-filter-dialog"
    :title="__('Save notification filter')"
    :description="__('Give this filtered notification view a short name so you can return to it later.')"
    :open="$open"
>
    <form method="POST" action="{{ $action }}" class="space-y-4">
        @csrf
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-muted">{{ __('Filter name') }}</span>
            <x-signal.ui.input name="name" value="{{ old('name') }}" maxlength="40" required class="ui-input" placeholder="{{ __('Website incidents') }}" :restore="false" />
            <x-forms.errors name="name" />
        </label>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save current filter') }}</x-signal.ui.button>
    </form>
</x-signal.overlays.modal>
