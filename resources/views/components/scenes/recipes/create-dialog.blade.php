@props(['open' => false])

<x-dialogs.modal
    id="recipe-create-dialog"
    :title="__('Add recipe')"
    :description="__('Define a reusable provisioning script for your servers.')"
    :open="$open"
    body-class="p-0"
>
    <form action="{{ route('recipes.store', ['dialog' => 'create-recipe']) }}" method="POST">
        @csrf
        <x-scenes.recipes._form field-prefix="recipe-create-" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="route('recipes.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Create Recipe') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
