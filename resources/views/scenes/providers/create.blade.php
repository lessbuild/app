<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Providers')"
        :route="route('providers.index')"
    />

    <x-scenes.providers.validation-errors />

    <div class="mx-auto max-w-4xl">
        <form action="{{ route('providers.store') }}" method="POST">
            @csrf
            <x-ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-primary px-5 py-5 sm:px-8">
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Integrations') }}</p>
                    <h1 class="mt-1 text-xl font-black text-primary">{{ __('Provider Information') }}</h1>
                    <p class="mt-1 text-sm text-secondary">{{ __('Please fill in the information below to add a new provider.') }}</p>
                </div>

                <x-scenes.providers._form />

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-8">
                    <x-ui.button :href="route('providers.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Create Provider') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>
</x-layouts.app>
