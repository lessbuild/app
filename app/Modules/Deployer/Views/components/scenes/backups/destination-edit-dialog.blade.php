@props([
    'destination',
    'destinationCatalog',
    'destinationPresets',
    'open' => false,
    'contentUrl' => null,
    'cancelUrl' => null,
])

@php
    $dialogId = 'backup-destination-edit-'.$destination->id;
    $dialogContentUrl = $contentUrl ?? route('backups.destinations.edit', ['destination' => $destination, 'return_to' => request()->fullUrlWithoutQuery('dialog')]);
@endphp

<x-signal.overlays.modal
    :id="$dialogId"
    :title="__('Edit backup destination')"
    :description="__('Update the connection or rotate credentials. Leave credential fields blank to retain the encrypted values.')"
    :open="$open"
    body-class="p-0"
    data-modal-content-loaded="{{ $open ? 'true' : 'false' }}"
    data-modal-content-url="{{ $open ? $dialogContentUrl : '' }}"
>
    <div data-modal-content>
        @if ($open)
            <x-scenes.backups.destination-edit-dialog-content
                :destination="$destination"
                :destination-catalog="$destinationCatalog"
                :destination-presets="$destinationPresets"
                :cancel-url="$cancelUrl"
            />
        @else
            <p class="p-5 text-sm text-muted">{{ __('Loading backup destination form…') }}</p>
        @endif
    </div>
</x-signal.overlays.modal>
