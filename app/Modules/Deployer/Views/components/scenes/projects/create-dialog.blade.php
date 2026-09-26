@props([
    'templates',
    'open' => false,
    'cancelUrl' => null,
])

@php($dialogCancelUrl = $cancelUrl ?? route('projects.index'))

<x-signal.overlays.modal
    id="application-create-dialog"
    :title="__('New application')"
    :description="__('Start from a production-ready template, then customize every runtime setting.')"
    :open="$open"
    body-class="p-0"
>
    <form method="POST" action="{{ route('projects.store', ['dialog' => 'create-application']) }}">
        @csrf
        <x-signal.ui.input type="hidden" name="_project_form" value="1" :restore="false" />
        <x-scenes.projects._create-form :templates="$templates" />

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
            <x-signal.ui.button :href="$dialogCancelUrl" variant="ghost" data-modal-cancel>{{ __('Cancel') }}</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="primary">{{ __('Create application') }}</x-signal.ui.button>
        </div>
    </form>
</x-signal.overlays.modal>
