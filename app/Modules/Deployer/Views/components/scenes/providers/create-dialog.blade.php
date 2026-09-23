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
    <x-scenes.providers.create-dialog-content
        :cancel-url="$dialogCancelUrl"
        :field-prefix="$fieldPrefix"
    />
</x-dialogs.modal>
