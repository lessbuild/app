@props([
    'types',
    'providers',
    'sizes',
    'images',
    'regions',
    'recipes',
    'planUsage',
    'open' => false,
    'indexQuery' => [],
    'cancelUrl' => null,
    'returnUrl' => null,
    'providerCreateUrl' => null,
    'providerCreateContentUrl' => null,
    'fieldPrefix' => 'server-create-',
])

@php
    $serverStoreUrl = route('servers.store', ['dialog' => 'create-server']);
    $dialogCancelUrl = $cancelUrl ?? route('servers.index', $indexQuery);
    $dialogReturnUrl = $returnUrl ?? request()->fullUrlWithoutQuery('dialog');
@endphp

<x-signal.overlays.modal
    id="server-create-dialog"
    :title="__('Add server')"
    :description="__('Choose a provider and infrastructure profile, then start server provisioning.')"
    :open="$open"
    body-class="p-0"
>
    <x-scenes.servers.create-dialog-content
        :types="$types"
        :providers="$providers"
        :sizes="$sizes"
        :images="$images"
        :regions="$regions"
        :recipes="$recipes"
        :plan-usage="$planUsage"
        :index-query="$indexQuery"
        :cancel-url="$dialogCancelUrl"
        :return-url="$dialogReturnUrl"
        :provider-create-url="$providerCreateUrl"
        :provider-create-content-url="$providerCreateContentUrl"
        :field-prefix="$fieldPrefix"
    />
</x-signal.overlays.modal>
