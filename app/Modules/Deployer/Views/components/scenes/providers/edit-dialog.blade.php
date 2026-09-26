@props([
    'provider',
    'open' => false,
    'fieldPrefix' => 'provider-edit-',
    'contentUrl' => null,
    'cancelUrl' => null,
])

@php($dialogContentUrl = $contentUrl ?? route('providers.edit', ['provider' => $provider, 'dialog' => 'edit-provider', 'fragment' => 1]))

<x-signal.overlays.modal
    id="provider-edit-dialog"
    :title="__('Edit provider')"
    :description="__('Update the credential label, token, and connection monitoring settings.')"
    :open="$open"
    body-class="p-0"
    data-modal-content-loaded="{{ $open ? 'true' : 'false' }}"
    data-modal-content-url="{{ $open ? $dialogContentUrl : '' }}"
>
    <div data-modal-content>
        @if ($open)
            <x-scenes.providers.edit-dialog-content
                :provider="$provider"
                :cancel-url="$cancelUrl"
                :field-prefix="$fieldPrefix"
            />
        @else
            <p class="p-5 text-sm text-muted">{{ __('Loading provider form…') }}</p>
        @endif
    </div>
</x-signal.overlays.modal>
