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
@php($dialogReturnUrl = $returnUrl ?? $dialogCancelUrl)
@php($serverCreateUrl ??= (string) \Illuminate\Support\Uri::of($dialogReturnUrl)->withQuery(['dialog' => 'create-server']))
@php($serverCreateContentUrl ??= route('dialogs.create', ['resource' => 'server', 'return_to' => $dialogReturnUrl]))

@if ($servers->isEmpty())
    <x-ui.alert tone="info" class="m-5" role="status">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p>{{ __('You need an active application server with MySQL before you can add a website.') }}</p>
            <a
                data-turbo="false"
                href="{{ $serverCreateUrl }}"
                data-modal-trigger="server-create-dialog"
                data-modal-content-url="{{ $serverCreateContentUrl }}"
                aria-controls="server-create-dialog"
                aria-expanded="false"
                class="shrink-0 font-semibold underline"
            >
                {{ __('Create server') }}
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </x-ui.alert>
@endif

@if (! $planUsage['allowed'])
    <x-ui.alert tone="warning" class="m-5" role="status">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p>{{ __('Your plan’s website limit has been reached.') }}</p>
            <a data-turbo="false" href="{{ route('billing.index') }}" class="shrink-0 font-semibold underline">
                {{ __('Upgrade plan') }}
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </x-ui.alert>
@endif

@error('plan')
    <x-ui.alert tone="danger" class="m-5">{{ $message }} <a class="font-bold underline" href="{{ route('billing.index') }}">{{ __('View plans') }}</a></x-ui.alert>
@enderror

<form action="{{ $websiteStoreUrl }}" method="POST">
    @csrf
    <input type="hidden" name="_website_form" value="1">
    <x-scenes.websites._form :servers="$servers" :field-prefix="$fieldPrefix" />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
        <x-ui.button :href="$dialogCancelUrl" variant="ghost">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary" :disabled="$servers->isEmpty() || ! $planUsage['allowed']">
            {{ __('Create website') }}
        </x-ui.button>
    </div>
</form>
