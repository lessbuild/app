@props([
    'open' => false,
    'fieldPrefix' => 'recipe-create-',
])

<x-signal.overlays.modal
    id="recipe-create-dialog"
    :title="__('Add recipe')"
    :description="__('Define a reusable provisioning script for your servers.')"
    :open="$open"
    body-class="p-0"
>
    <form action="{{ route('recipes.store', ['dialog' => 'create-recipe']) }}" method="POST">
        @csrf
        <x-scenes.recipes._form :field-prefix="$fieldPrefix" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
            <x-signal.ui.button :href="route('recipes.index')" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="primary">{{ __('Create Recipe') }}</x-signal.ui.button>
        </div>
    </form>
</x-signal.overlays.modal>
