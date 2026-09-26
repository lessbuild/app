@props([
    'inspectRecipe' => null,
    'open' => false,
    'recipe',
])

@php($dialogId = 'gallery-inspect-script-'.$recipe->id)

<x-signal.overlays.modal
    :id="$dialogId"
    :title="__('Inspect :recipe', ['recipe' => $recipe->name])"
    :description="__('Review the commands before using this recipe on a server.')"
    :open="$open"
    :data-modal-content-loaded="$open ? 'true' : 'false'"
>
    <div data-modal-content class="space-y-4">
        @if ($open)
            @include('scenes.gallery.partials.script-modal-content', ['recipe' => $inspectRecipe ?? $recipe])
        @else
            <p class="text-sm text-muted">{{ __('Loading script preview…') }}</p>
        @endif
    </div>
</x-signal.overlays.modal>
