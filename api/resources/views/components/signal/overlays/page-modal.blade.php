@props([
    'id',
    'title',
    'src',
    'description' => null,
    'size' => 'large',
])

{{-- A modal that opens one of the app's form pages in place: the page is fetched when the modal opens and the part it
     marks with data-modal-content is shown (see resources/js/remote-fragments.js). The link that opens it still goes to
     the page when JavaScript isn't running. After a failed submit the modal reopens, and the errors and entered values
     are kept for one more request so the reloaded form shows them. --}}
@php($retry = $errors->any() && old('_modal') === $id)
@if ($retry)
    @php(session()->reflash())
@endif
<x-signal.overlays.modal :id="$id" :title="$title" :description="$description" :open="$retry" @class(['ui-dialog-large' => $size === 'large', 'ui-dialog-wide' => $size === 'wide'])>
    <div data-fragment-src="{{ $src }}" data-fragment-fallback="{{ __('Couldn’t load the form here. Open it on its own page.') }}" class="grid gap-5">
        <p class="text-sm text-muted" role="status">{{ __('Loading…') }}</p>
    </div>
</x-signal.overlays.modal>
