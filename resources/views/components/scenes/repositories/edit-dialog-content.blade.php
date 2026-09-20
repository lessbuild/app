@props([
    'repository',
    'providers',
    'websites',
    'cancelUrl' => null,
    'fieldPrefix' => 'repository-edit-',
])

<form action="{{ route('repositories.update', ['repository' => $repository, 'dialog' => 'edit-repository']) }}" method="POST">
    @csrf
    @method('PATCH')
    <x-scenes.repositories._form
        :providers="$providers"
        :websites="$websites"
        :repository="$repository"
        :field-prefix="$fieldPrefix"
    />

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
        <x-ui.button :href="$cancelUrl ?? route('repositories.show', $repository)" variant="ghost">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary">{{ __('Save Repository') }}</x-ui.button>
    </div>
</form>
