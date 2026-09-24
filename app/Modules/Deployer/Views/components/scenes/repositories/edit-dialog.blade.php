@props([
    'repository',
    'providers',
    'websites',
    'open' => false,
    'fieldPrefix' => 'repository-edit-',
    'contentUrl' => null,
    'cancelUrl' => null,
])

@php($dialogContentUrl = $contentUrl ?? route('repositories.edit', ['repository' => $repository, 'dialog' => 'edit-repository', 'fragment' => 1]))

<x-signal.overlays.modal
    id="repository-edit-dialog"
    :title="__('Edit repository')"
    :description="__('Update the deployment target, source settings, and deployment hooks.')"
    :open="$open"
    body-class="p-0"
    data-modal-content-loaded="{{ $open ? 'true' : 'false' }}"
    data-modal-content-url="{{ $open ? $dialogContentUrl : '' }}"
>
    <div data-modal-content>
        @if ($open)
            <x-scenes.repositories.edit-dialog-content
                :repository="$repository"
                :providers="$providers"
                :websites="$websites"
                :cancel-url="$cancelUrl"
                :field-prefix="$fieldPrefix"
            />
        @else
            <p class="p-5 text-sm text-muted">{{ __('Loading repository form…') }}</p>
        @endif
    </div>
</x-signal.overlays.modal>
