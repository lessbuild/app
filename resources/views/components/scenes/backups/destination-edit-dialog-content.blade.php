@props([
    'destination',
    'destinationCatalog',
    'destinationPresets',
    'cancelUrl' => null,
])

@include('backups._destination-form', [
    'action' => route('backups.destinations.update', $destination),
    'formId' => 'backup-destination-edit-'.$destination->id,
    'formMarker' => 'edit',
    'destinationId' => $destination->id,
    'submitLabel' => __('Save connection'),
    'destination' => $destination,
    'destinationCatalog' => $destinationCatalog,
    'destinationPresets' => $destinationPresets,
    'cancelUrl' => $cancelUrl,
])
