<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :route="route('servers.index')"
        :title="__('Back to servers')"
    />

    @if ($providers->isEmpty())
        <x-ui.alert tone="warning" class="my-4">
            <p class="font-semibold">{{ __('You must add a cloud provider before you can add a server') }}</p>
            <x-ui.button :href="route('providers.create')" variant="secondary" class="mt-3">{{ __('Add cloud provider') }}</x-ui.button>
        </x-ui.alert>
    @endif

    @if (! $planUsage['allowed'])
        <x-ui.alert tone="warning" class="my-4">
            <p class="font-semibold">{{ __('Your plan’s server limit has been reached') }}</p>
            <x-ui.button :href="route('billing.index')" variant="secondary" class="mt-3">{{ __('Upgrade plan') }}</x-ui.button>
        </x-ui.alert>
    @endif

    @error('plan')
        <x-ui.alert tone="danger" class="my-4">
            {{ $message }}
            <a class="font-bold underline" href="{{ route('billing.index') }}">{{ __('View plans') }}</a>
        </x-ui.alert>
    @enderror

    <form action="{{ route('servers.store') }}" method="POST">
        @csrf
        <x-ui.card class="mt-8 overflow-hidden">
            <div class="border-b border-primary px-5 py-5 sm:px-8">
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Infrastructure') }}</p>
                <h1 class="mt-1 text-xl font-black text-primary">{{ __('Server Information') }}</h1>
                <p class="mt-1 text-sm text-secondary">{{ __('Please fill in the information below to create a new server.') }}</p>
            </div>

            <x-scenes.servers._form
                :types="$types"
                :providers="$providers"
                :sizes="$sizes"
                :images="$images"
                :regions="$regions"
                :recipes="$recipes"
            />

            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-8">
                <x-ui.button :href="route('servers.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" :disabled="$providers->isEmpty() || ! $planUsage['allowed']">
                    {{ __('Create Server') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    </form>
</x-layouts.app>
