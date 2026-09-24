@props([
    'recipe',
    'cancelUrl' => null,
    'dialogKey' => 'edit-recipe',
    'fieldPrefix' => 'recipe-edit-',
])

@if ($recipe->source && $recipe->hasGalleryUpdate())
    <x-signal.ui.alert tone="warning" class="m-5 mb-0">
        {{ __('A newer gallery revision is available. Review it before replacing your private snapshot.') }}
    </x-signal.ui.alert>
@endif

<form action="{{ route('recipes.update', ['recipe' => $recipe, 'dialog' => $dialogKey]) }}" method="POST">
    @csrf
    @method('PATCH')
    <x-signal.ui.input type="hidden" name="_recipe_form" value="edit" :restore="false" />
    <x-scenes.recipes._form :recipe="$recipe" :field-prefix="$fieldPrefix" />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
        <x-signal.ui.button :href="$cancelUrl ?? route('recipes.show', $recipe)" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
        <x-signal.ui.button type="submit" variant="primary">{{ __('Save Recipe') }}</x-signal.ui.button>
    </div>
</form>
