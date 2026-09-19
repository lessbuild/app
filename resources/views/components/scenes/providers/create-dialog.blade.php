@props([
    'open' => false,
    'cancelUrl' => null,
    'fieldPrefix' => 'provider-create-',
])

@php($dialogCancelUrl = $cancelUrl ?? route('providers.index'))

<x-dialogs.modal
    id="provider-create-dialog"
    :title="__('Add provider')"
    :description="__('Connect an infrastructure or source-control credential to this workspace.')"
    :open="$open"
    body-class="p-0"
>
    <x-scenes.providers.validation-errors />

    <form action="{{ route('providers.store', ['dialog' => 'create-provider']) }}" method="POST">
        @csrf
        <input type="hidden" name="_provider_form" value="1">
        <x-scenes.providers._form :field-prefix="$fieldPrefix" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="$dialogCancelUrl" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Add Provider') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
