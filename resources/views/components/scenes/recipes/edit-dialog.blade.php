@props([
    'recipe',
    'open' => false,
    'id' => 'recipe-edit-dialog',
])

<x-dialogs.modal
    :id="$id"
    :title="__('Edit recipe')"
    :description="__('Changes apply when this recipe is used for a new server.')"
    :open="$open"
    body-class="p-0"
>
    @if ($recipe->source && $recipe->hasGalleryUpdate())
        <x-ui.alert tone="warning" class="m-5 mb-0">
            {{ __('A newer gallery revision is available. Review it before replacing your private snapshot.') }}
        </x-ui.alert>
    @endif

    <form action="{{ route('recipes.update', ['recipe' => $recipe, 'dialog' => 'edit-recipe']) }}" method="POST">
        @csrf
        @method('PATCH')
        <x-scenes.recipes._form :recipe="$recipe" field-prefix="recipe-edit-" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="route('recipes.show', $recipe)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Save Recipe') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
