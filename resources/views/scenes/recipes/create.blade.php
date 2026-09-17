<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    <form method="POST" action="{{ route('recipes.store') }}">
        @csrf
        <x-forms.section
            :title="__('Create Recipe')"
            :description="__('Define a reusable provisioning script for your servers.')"
        >
            <x-scenes.recipes._form />

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <x-ui.button type="submit" variant="primary">{{ __('Create Recipe') }}</x-ui.button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>
</x-layouts.app>
