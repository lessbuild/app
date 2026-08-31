<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('recipes.index')" :title="__('Back to recipes')" />

    @if (session('status'))
        <div class="my-4 rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('recipes.update', $recipe) }}">
        @csrf
        @method('PATCH')
        <x-forms.section
            :title="__('Edit Recipe')"
            :description="__('Changes apply when this recipe is used for a new server.')"
        >
            <x-scenes.recipes._form :recipe="$recipe" />

            <x-slot:footer>
                <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                    <button class="button primary" type="submit">{{ __('Save Recipe') }}</button>
                </div>
            </x-slot:footer>
        </x-forms.section>
    </form>
</x-layouts.app>
