@props([
    'cancelUrl' => null,
    'fieldPrefix' => 'provider-create-',
])

@php($dialogCancelUrl = $cancelUrl ?? route('providers.index'))

<x-scenes.providers.validation-errors />

<form action="{{ route('providers.store', ['dialog' => 'create-provider']) }}" method="POST">
    @csrf
    <x-signal.ui.input type="hidden" name="_provider_form" value="1" :restore="false" />
    <x-scenes.providers._form :field-prefix="$fieldPrefix" />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
        <x-signal.ui.button :href="$dialogCancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Add Provider') }}</x-signal.ui.button>
    </div>
</form>
