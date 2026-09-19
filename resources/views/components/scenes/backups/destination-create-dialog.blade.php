@props([
    'destinationCatalog',
    'destinationPresets',
    'open' => false,
])

<x-dialogs.modal
    id="backup-destination-create-dialog"
    :title="__('Add backup destination')"
    :description="__('Connect encrypted offsite storage for website backups and recovery verification.')"
    :open="$open"
    body-class="p-0"
>
    @include('backups._destination-form', [
        'action' => route('backups.destinations.store', ['dialog' => 'add-destination']),
        'formId' => 'backup-destination-create',
        'formMarker' => 'create',
        'submitLabel' => __('Create encrypted destination'),
        'destination' => null,
        'destinationCatalog' => $destinationCatalog,
        'destinationPresets' => $destinationPresets,
    ])
</x-dialogs.modal>
