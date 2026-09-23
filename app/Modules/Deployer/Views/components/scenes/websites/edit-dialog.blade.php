@props([
    'website',
    'servers',
    'open' => false,
    'fieldPrefix' => 'website-edit-',
    'contentUrl' => null,
    'cancelUrl' => null,
])

@php($dialogContentUrl = $contentUrl ?? route('websites.edit', ['website' => $website, 'dialog' => 'edit-website', 'fragment' => 1]))

<x-dialogs.modal
    id="website-edit-dialog"
    :title="__('Edit website')"
    :description="__('Update placement, environment, retention, and health monitoring settings.')"
    :open="$open"
    body-class="p-0"
    data-modal-content-loaded="{{ $open ? 'true' : 'false' }}"
    data-modal-content-url="{{ $open ? $dialogContentUrl : '' }}"
>
    <div data-modal-content>
        @if ($open)
            <x-scenes.websites.edit-dialog-content
                :website="$website"
                :servers="$servers"
                :cancel-url="$cancelUrl"
                :field-prefix="$fieldPrefix"
            />
        @else
            <p class="p-5 text-sm text-muted">{{ __('Loading website form…') }}</p>
        @endif
    </div>
</x-dialogs.modal>
