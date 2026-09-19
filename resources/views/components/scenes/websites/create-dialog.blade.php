@props([
    'servers',
    'planUsage',
    'websiteIndexQuery' => [],
    'websiteStoreUrl',
    'open' => false,
])

<x-dialogs.modal
    id="website-create-dialog"
    :title="__('Add website')"
    :description="__('Choose a server, configure deployment health checks, and create a new deployment target.')"
    :open="$open"
    body-class="p-0"
>
    @if ($servers->isEmpty())
        <x-ui.alert tone="info" class="m-5" role="status">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('You need an active application server with MySQL before you can add a website.') }}</p>
                <a data-turbo="false" href="{{ route('servers.index', ['dialog' => 'create-server']) }}" class="shrink-0 font-semibold underline">
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
        <x-scenes.websites._form :servers="$servers" field-prefix="website-create-" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="route('websites.index', $websiteIndexQuery)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" :disabled="$servers->isEmpty() || ! $planUsage['allowed']">
                {{ __('Create website') }}
            </x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
