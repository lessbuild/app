@props([
    'servers',
    'planUsage',
    'websiteIndexQuery' => [],
    'websiteStoreUrl',
    'open' => false,
    'cancelUrl' => null,
    'returnUrl' => null,
    'serverCreateUrl' => null,
    'serverCreateContentUrl' => null,
    'fieldPrefix' => 'website-create-',
])

@php($dialogCancelUrl = $cancelUrl ?? route('websites.index', $websiteIndexQuery))
@php($dialogReturnUrl = $returnUrl ?? request()->fullUrlWithoutQuery('dialog'))

<x-signal.overlays.modal
    id="website-create-dialog"
    :title="__('Add website')"
    :description="__('Choose a server, configure deployment health checks, and create a new deployment target.')"
    :open="$open"
    body-class="p-0"
>
    <x-scenes.websites.create-dialog-content
        :servers="$servers"
        :plan-usage="$planUsage"
        :website-index-query="$websiteIndexQuery"
        :website-store-url="$websiteStoreUrl"
        :cancel-url="$dialogCancelUrl"
        :return-url="$dialogReturnUrl"
        :server-create-url="$serverCreateUrl"
        :server-create-content-url="$serverCreateContentUrl"
        :field-prefix="$fieldPrefix"
    />
</x-signal.overlays.modal>
