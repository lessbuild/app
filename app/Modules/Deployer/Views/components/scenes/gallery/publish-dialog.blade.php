@props([
    'cancelUrl',
    'open' => false,
])

<x-signal.overlays.modal
    id="gallery-publish-recipe-dialog"
    :title="__('Publish a recipe')"
    :description="__('Share a reviewed provisioning script with the community gallery.')"
    :open="$open"
    body-class="p-0"
>
    <form method="POST" action="{{ route('recipes.store') }}">
        @csrf
        <x-signal.ui.input type="hidden" name="_recipe_publish_form" value="1" :restore="false" />
        <x-scenes.recipes._form />
        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-4 py-4 sm:px-6">
            <x-signal.ui.button href="{{ $cancelUrl }}" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="primary">{{ __('Publish Recipe') }}</x-signal.ui.button>
        </div>
    </form>
</x-signal.overlays.modal>
