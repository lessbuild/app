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
@php($dialogReturnUrl = $returnUrl ?? $dialogCancelUrl)
@php($providerCreateUrl ??= (string) \Illuminate\Support\Uri::of($dialogReturnUrl)->withQuery(['dialog' => 'create-provider']))
@php($providerCreateContentUrl ??= route('dialogs.create', ['resource' => 'provider', 'return_to' => $dialogReturnUrl]))
@php($websiteCreateUrl ??= (string) \Illuminate\Support\Uri::of($dialogReturnUrl)->withQuery(['dialog' => 'create-website']))
@php($websiteCreateContentUrl ??= route('dialogs.create', ['resource' => 'website', 'return_to' => $dialogReturnUrl]))

@if ($providers->isEmpty())
    <x-signal.ui.card as="aside" class="m-5 flex flex-wrap items-center justify-between gap-3 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-primary)" role="status" :shadow="false">
            <p>{{ __('You must add a source control provider before you can add a repository') }}</p>
            <x-signal.ui.button
                :href="$providerCreateUrl"
                data-modal-trigger="provider-create-dialog"
                data-modal-content-url="{{ $providerCreateContentUrl }}"
                aria-controls="provider-create-dialog"
                aria-expanded="false"
                variant="secondary"
            >{{ __('Add source provider') }}</x-signal.ui.button>
    </x-signal.ui.card>
@endif

@if ($websites->isEmpty())
    <x-signal.ui.card as="aside" class="m-5 flex flex-wrap items-center justify-between gap-3 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-primary)" role="status" :shadow="false">
            <p>{{ __('You need an active website before you can add a repository') }}</p>
            <x-signal.ui.button
                :href="$websiteCreateUrl"
                data-modal-trigger="website-create-dialog"
                data-modal-content-url="{{ $websiteCreateContentUrl }}"
                aria-controls="website-create-dialog"
                aria-expanded="false"
                variant="secondary"
            >{{ __('Create Website') }}</x-signal.ui.button>
    </x-signal.ui.card>
@endif

<form action="{{ route('repositories.store', ['dialog' => 'create-repository']) }}" method="POST">
    @csrf
    <input type="hidden" name="_repository_form" value="1">
    <x-scenes.repositories._form
        :providers="$providers"
        :websites="$websites"
        :field-prefix="$fieldPrefix"
    />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
        <x-signal.ui.button :href="$dialogCancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
        <x-signal.ui.button type="submit" variant="primary" :disabled="$providers->isEmpty() || $websites->isEmpty()">
            {{ __('Create Repository') }}
        </x-signal.ui.button>
    </div>
</form>
