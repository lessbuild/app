@props(['receiptKey', 'receiptToken'])

<x-signal.ui.panel as="section" class="space-y-4 p-6" aria-labelledby="deletion-receipt-heading">
    <h2 id="deletion-receipt-heading" class="text-lg font-extrabold text-ink">{{ __('Save your recovery receipt') }}</h2>
    <p class="text-sm leading-6 text-muted">{{ __('Keep this private. It lets you follow progress and retry unfinished cleanup after you are signed out. Save the progress link and recovery key before continuing.') }}</p>
    <x-signal.ui.input-field name="receipt_url" :label="__('Progress link')" :value="route('platform.deletions.progress', $receiptKey)" :restore="false" readonly autocomplete="off" />
    <x-signal.ui.input-field name="receipt_token" :label="__('Recovery key')" :value="$receiptToken" :restore="false" readonly autocomplete="off" class="font-mono" />
</x-signal.ui.panel>
