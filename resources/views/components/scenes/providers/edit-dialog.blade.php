@props([
    'provider',
    'open' => false,
])

<x-dialogs.modal
    id="provider-edit-dialog"
    :title="__('Edit provider')"
    :description="__('Update the credential label, token, and connection monitoring settings.')"
    :open="$open"
    body-class="p-0"
>
    <x-scenes.providers.validation-errors />

    <form action="{{ route('providers.update', ['provider' => $provider, 'dialog' => 'edit-provider']) }}" method="POST">
        @csrf
        @method('PATCH')
        <x-scenes.providers._form :provider="$provider" field-prefix="provider-edit-" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="route('providers.show', $provider)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Save Provider') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
