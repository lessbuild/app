@props([
    'action',
    'open' => false,
])

<x-dialogs.modal
    id="notification-save-filter-dialog"
    :title="__('Save notification filter')"
    :description="__('Give this filtered notification view a short name so you can return to it later.')"
    :open="$open"
>
    <form method="POST" action="{{ $action }}" class="space-y-4">
        @csrf
        <label class="block">
            <span class="mb-1 block text-xs font-bold uppercase text-muted">{{ __('Filter name') }}</span>
            <input name="name" value="{{ old('name') }}" maxlength="40" required class="input secondary w-full rounded-lg" placeholder="{{ __('Website incidents') }}">
            <x-forms.errors name="name" />
        </label>
        <x-ui.button type="submit" variant="primary">{{ __('Save current filter') }}</x-ui.button>
    </form>
</x-dialogs.modal>
