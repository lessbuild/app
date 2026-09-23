@props([
    'cancelUrl',
    'open' => false,
])

<x-dialogs.modal
    id="gallery-publish-recipe-dialog"
    :title="__('Publish a recipe')"
    :description="__('Share a reviewed provisioning script with the community gallery.')"
    :open="$open"
    body-class="p-0"
>
    <form method="POST" action="{{ route('recipes.store') }}">
        @csrf
        <input type="hidden" name="_recipe_publish_form" value="1">
        <x-scenes.recipes._form />
        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-4 py-4 sm:px-6">
            <x-ui.button href="{{ $cancelUrl }}" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Publish Recipe') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
