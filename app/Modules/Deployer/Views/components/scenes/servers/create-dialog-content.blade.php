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
    <x-signal.ui.alert tone="warning" class="m-5">
        <p class="font-semibold">{{ __('You must add a cloud provider before you can add a server.') }}</p>
        <x-signal.ui.button
            :href="$providerCreateUrl"
            data-modal-trigger="provider-create-dialog"
            data-modal-content-url="{{ $providerCreateContentUrl }}"
            aria-controls="provider-create-dialog"
            aria-expanded="false"
            variant="secondary"
            class="mt-3"
        >{{ __('Add cloud provider') }}</x-signal.ui.button>
    </x-signal.ui.alert>
@endif

@if (! $planUsage['plan_available'] || ! $planUsage['limit_configured'])
    <x-signal.ui.alert tone="warning" class="m-5">
        {{ __('We could not confirm this workspace’s Deployer plan and server allowance. Retry shortly or contact support.') }}
    </x-signal.ui.alert>
@elseif (! $planUsage['allowed'])
    <x-signal.ui.alert tone="warning" class="m-5">
        <p class="font-semibold">{{ __('Your plan’s server limit has been reached.') }}</p>
        <x-signal.ui.button :href="route('billing.index')" variant="secondary" class="mt-3">{{ __('Upgrade plan') }}</x-signal.ui.button>
    </x-signal.ui.alert>
@endif

@error('plan')
    <x-signal.ui.alert tone="danger" class="m-5">
        {{ $message }}
        <a class="font-bold underline" href="{{ route('billing.index') }}">{{ __('View plans') }}</a>
    </x-signal.ui.alert>
@enderror

<form action="{{ $serverStoreUrl }}" method="POST">
    @csrf
    <x-signal.ui.input type="hidden" name="_server_form" value="1" :restore="false" />
    <x-scenes.servers._form
        :types="$types"
        :providers="$providers"
        :sizes="$sizes"
        :images="$images"
        :regions="$regions"
        :recipes="$recipes"
        :field-prefix="$fieldPrefix"
    />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
        <x-signal.ui.button :href="$dialogCancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
        <x-signal.ui.button type="submit" variant="primary" :disabled="$providers->isEmpty() || ! $planUsage['allowed']">
            {{ __('Create server') }}
        </x-signal.ui.button>
    </div>
</form>
