@props([
    'providers',
    'websites',
    'indexQuery' => [],
    'open' => false,
    'cancelUrl' => null,
    'returnUrl' => null,
    'providerCreateUrl' => null,
    'providerCreateContentUrl' => null,
    'websiteCreateUrl' => null,
    'websiteCreateContentUrl' => null,
    'fieldPrefix' => 'repository-create-',
])

@php($dialogCancelUrl = $cancelUrl ?? route('repositories.index', $indexQuery))

<x-dialogs.modal
    id="repository-create-dialog"
    :title="__('Add repository')"
    :description="__('Connect a source repository to an active website and deployment branch.')"
    :open="$open"
    body-class="p-0"
>
    <x-scenes.repositories.create-dialog-content
        :providers="$providers"
        :websites="$websites"
        :index-query="$indexQuery"
        :cancel-url="$dialogCancelUrl"
        :return-url="$returnUrl ?? request()->fullUrlWithoutQuery('dialog')"
        :provider-create-url="$providerCreateUrl"
        :provider-create-content-url="$providerCreateContentUrl"
        :website-create-url="$websiteCreateUrl"
        :website-create-content-url="$websiteCreateContentUrl"
        :field-prefix="$fieldPrefix"
    />
</x-dialogs.modal>
