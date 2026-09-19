@props([
    'templates',
    'open' => false,
    'cancelUrl' => null,
])

@php($dialogCancelUrl = $cancelUrl ?? route('projects.index'))

<x-dialogs.modal
    id="application-create-dialog"
    :title="__('New application')"
    :description="__('Start from a production-ready template, then customize every runtime setting.')"
    :open="$open"
    body-class="p-0"
>
    <form method="POST" action="{{ route('projects.store', ['dialog' => 'create-application']) }}">
        @csrf
        <input type="hidden" name="_project_form" value="1">
        <x-scenes.projects._create-form :templates="$templates" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button :href="$dialogCancelUrl" variant="ghost">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Create application') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
