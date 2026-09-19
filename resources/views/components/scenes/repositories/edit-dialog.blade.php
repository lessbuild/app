@props([
    'repository',
    'providers',
    'websites',
    'open' => false,
])

<x-dialogs.modal
    id="repository-edit-dialog"
    :title="__('Edit repository')"
    :description="__('Update the deployment target, source settings, and deployment hooks.')"
    :open="$open"
    body-class="p-0"
>
    <form action="{{ route('repositories.update', ['repository' => $repository, 'dialog' => 'edit-repository']) }}" method="POST">
        @csrf
        @method('PATCH')
        <x-scenes.repositories._form
            :providers="$providers"
            :websites="$websites"
            :repository="$repository"
            field-prefix="repository-edit-"
        />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="route('repositories.show', $repository)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Save Repository') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
