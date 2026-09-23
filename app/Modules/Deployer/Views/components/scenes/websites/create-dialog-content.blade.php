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
    <aside class="ui-panel m-5 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-primary)" role="status">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p>{{ __('You need an active application server with MySQL before you can add a website.') }}</p>
            <a
                data-turbo="false"
                href="{{ $serverCreateUrl }}"
                data-modal-trigger="server-create-dialog"
                data-modal-content-url="{{ $serverCreateContentUrl }}"
                aria-controls="server-create-dialog"
                aria-expanded="false"
                class="ui-link shrink-0 font-semibold"
            >
                {{ __('Create server') }}
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </aside>
@endif

@if (! $planUsage['plan_available'] || ! $planUsage['limit_configured'])
    <x-ui.alert tone="warning" class="m-5">
        {{ __('We could not confirm this workspace’s Deployer plan and website allowance. Retry shortly or contact support.') }}
    </x-ui.alert>
@elseif (! $planUsage['allowed'])
    <aside class="ui-panel m-5 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-warning)" role="status">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p>{{ __('Your plan’s website limit has been reached.') }}</p>
            <a data-turbo="false" href="{{ route('billing.index') }}" class="ui-link shrink-0 font-semibold">
                {{ __('Upgrade plan') }}
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </aside>
@endif

@error('plan')
    <aside class="ui-panel m-5 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-danger)" role="alert">
        {{ $message }} <a class="ui-link font-bold" href="{{ route('billing.index') }}">{{ __('View plans') }}</a>
    </aside>
@enderror

<form action="{{ $websiteStoreUrl }}" method="POST">
    @csrf
    <input type="hidden" name="_website_form" value="1">
    <x-scenes.websites._form :servers="$servers" :field-prefix="$fieldPrefix" />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
        <x-ui.button :href="$dialogCancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary" :disabled="$servers->isEmpty() || ! $planUsage['allowed']">
            {{ __('Create website') }}
        </x-ui.button>
    </div>
</form>
