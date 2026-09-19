@props([
    'templates',
    'open' => false,
])

<x-dialogs.modal
    id="application-create-dialog"
    :title="__('New application')"
    :description="__('Start from a production-ready template, then customize every runtime setting.')"
    :open="$open"
    body-class="p-0"
>
    <form method="POST" action="{{ route('projects.store', ['dialog' => 'create-application']) }}">
        @csrf
        <x-scenes.projects._create-form :templates="$templates" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="route('projects.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Create application') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
