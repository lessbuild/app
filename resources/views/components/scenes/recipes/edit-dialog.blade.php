@props([
    'recipe',
    'open' => false,
    'id' => 'recipe-edit-dialog',
    'cancelUrl' => null,
    'contentUrl' => null,
    'dialogKey' => 'edit-recipe',
    'fieldPrefix' => 'recipe-edit-',
])

@php($dialogCancelUrl = $cancelUrl ?? route('recipes.show', $recipe))
@php($dialogContentUrl = $contentUrl ?? route('recipes.edit', ['recipe' => $recipe, 'dialog' => $dialogKey, 'fragment' => 1]))

<x-dialogs.modal
    :id="$id"
    :title="__('Edit recipe')"
    :description="__('Changes apply when this recipe is used for a new server.')"
    :open="$open"
    body-class="p-0"
    data-modal-content-loaded="{{ $open ? 'true' : 'false' }}"
    data-modal-content-url="{{ $open ? $dialogContentUrl : '' }}"
>
    <div data-modal-content>
        @if ($open)
            <x-scenes.recipes.edit-dialog-content
                :recipe="$recipe"
                :cancel-url="$dialogCancelUrl"
                :dialog-key="$dialogKey"
                :field-prefix="$fieldPrefix"
            />
        @else
            <p class="p-5 text-sm text-secondary">{{ __('Loading recipe form…') }}</p>
        @endif
    </div>
</x-dialogs.modal>
