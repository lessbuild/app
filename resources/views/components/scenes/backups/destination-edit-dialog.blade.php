@props([
    'destination',
    'destinationCatalog',
    'destinationPresets',
    'open' => false,
])

@php
    $dialogId = 'backup-destination-edit-'.$destination->id;
    $dialogUrl = route('backups.index', ['dialog' => 'edit-destination-'.$destination->id]);
@endphp

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Edit backup destination')"
    :description="__('Update the connection or rotate credentials. Leave credential fields blank to retain the encrypted values.')"
    :open="$open"
    body-class="p-0"
>
    @include('backups._destination-form', [
        'action' => route('backups.destinations.update', $destination),
        'formId' => $dialogId,
        'formMarker' => 'edit',
        'destinationId' => $destination->id,
        'submitLabel' => __('Save connection'),
        'destination' => $destination,
        'destinationCatalog' => $destinationCatalog,
        'destinationPresets' => $destinationPresets,
    ])
</x-dialogs.modal>
