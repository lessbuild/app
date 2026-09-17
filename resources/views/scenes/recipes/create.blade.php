<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    <form method="POST" action="{{ route('recipes.store') }}" class="mx-auto mt-8 max-w-4xl">
        @csrf
        <x-ui.card class="overflow-hidden">
            <div class="border-b border-primary px-5 py-5 sm:px-8">
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Server operations') }}</p>
                <h1 class="mt-1 text-xl font-black text-primary">{{ __('Create Recipe') }}</h1>
                <p class="mt-1 text-sm text-secondary">{{ __('Define a reusable provisioning script for your servers.') }}</p>
            </div>
            <x-scenes.recipes._form />
            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-8">
                <x-ui.button :href="route('recipes.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary">{{ __('Create Recipe') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</x-layouts.app>
