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
    $dialogReturnUrl = $returnUrl ?? $dialogCancelUrl;
    $providerCreateUrl ??= (string) \Illuminate\Support\Uri::of($dialogReturnUrl)->withQuery(['dialog' => 'create-provider']);
    $providerCreateContentUrl ??= route('dialogs.create', ['resource' => 'provider', 'return_to' => $dialogReturnUrl]);
@endphp

@if ($providers->isEmpty())
    <x-ui.alert tone="warning" class="m-5">
        <p class="font-semibold">{{ __('You must add a cloud provider before you can add a server.') }}</p>
        <x-ui.button
            :href="$providerCreateUrl"
            data-modal-trigger="provider-create-dialog"
            data-modal-content-url="{{ $providerCreateContentUrl }}"
            aria-controls="provider-create-dialog"
            aria-expanded="false"
            variant="secondary"
            class="mt-3"
        >{{ __('Add cloud provider') }}</x-ui.button>
    </x-ui.alert>
@endif

@if (! $planUsage['allowed'])
    <x-ui.alert tone="warning" class="m-5">
        <p class="font-semibold">{{ __('Your plan’s server limit has been reached.') }}</p>
        <x-ui.button :href="route('billing.index')" variant="secondary" class="mt-3">{{ __('Upgrade plan') }}</x-ui.button>
    </x-ui.alert>
@endif

@error('plan')
    <x-ui.alert tone="danger" class="m-5">
        {{ $message }}
        <a class="font-bold underline" href="{{ route('billing.index') }}">{{ __('View plans') }}</a>
    </x-ui.alert>
@enderror

<form action="{{ $serverStoreUrl }}" method="POST">
    @csrf
    <input type="hidden" name="_server_form" value="1">
    <x-scenes.servers._form
        :types="$types"
        :providers="$providers"
        :sizes="$sizes"
        :images="$images"
        :regions="$regions"
        :recipes="$recipes"
        :field-prefix="$fieldPrefix"
    />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
        <x-ui.button :href="$dialogCancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary" :disabled="$providers->isEmpty() || ! $planUsage['allowed']">
            {{ __('Create server') }}
        </x-ui.button>
    </div>
</form>
