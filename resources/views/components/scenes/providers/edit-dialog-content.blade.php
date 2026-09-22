@props([
    'provider',
    'cancelUrl' => null,
    'fieldPrefix' => 'provider-edit-',
])

<x-scenes.providers.validation-errors />

<form action="{{ route('providers.update', ['provider' => $provider, 'dialog' => 'edit-provider']) }}" method="POST">
    @csrf
    @method('PATCH')
    <x-scenes.providers._form :provider="$provider" :field-prefix="$fieldPrefix" />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
        <x-ui.button :href="$cancelUrl ?? route('providers.show', $provider)" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary">{{ __('Save Provider') }}</x-ui.button>
    </div>
</form>
