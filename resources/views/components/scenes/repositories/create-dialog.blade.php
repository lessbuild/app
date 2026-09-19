@props([
    'providers',
    'websites',
    'indexQuery' => [],
    'open' => false,
    'fieldPrefix' => 'repository-create-',
])

<x-dialogs.modal
    id="repository-create-dialog"
    :title="__('Add repository')"
    :description="__('Connect a source repository to an active website and deployment branch.')"
    :open="$open"
    body-class="p-0"
>
    @if ($providers->isEmpty())
        <div class="m-5">
            <x-ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You must add a source control provider before you can add a repository') }}</p>
                <x-ui.button :href="route('providers.index', ['dialog' => 'create-provider'])" variant="secondary">{{ __('Add source provider') }}</x-ui.button>
            </x-ui.alert>
        </div>
    @endif

    @if ($websites->isEmpty())
        <div class="m-5">
            <x-ui.alert tone="info" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You need an active website before you can add a repository') }}</p>
                <x-ui.button :href="route('websites.index', ['dialog' => 'create-website'])" variant="secondary">{{ __('Create Website') }}</x-ui.button>
            </x-ui.alert>
        </div>
    @endif

    <form action="{{ route('repositories.store', ['dialog' => 'create-repository']) }}" method="POST">
        @csrf
        <x-scenes.repositories._form
            :providers="$providers"
            :websites="$websites"
            :field-prefix="$fieldPrefix"
        />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="route('repositories.index', $indexQuery)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" :disabled="$providers->isEmpty() || $websites->isEmpty()">
                {{ __('Create Repository') }}
            </x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
