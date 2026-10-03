@props([
    'id',
    'title',
    'action',
    'description' => null,
    'method' => 'POST',
    'submit' => null,
    'submitVariant' => 'primary',
    'formClass' => 'grid gap-5',
])

{{-- A modal holding one form: the CSRF token, method spoofing and the hidden _modal field that reopens it with its
     errors are built in, and the submit button sits at the bottom right. Put the fields in the slot. --}}
<x-signal.overlays.modal :id="$id" :title="$title" :description="$description" {{ $attributes }}>
    <form method="POST" action="{{ $action }}" class="{{ $formClass }}" @if (isset($enctype)) enctype="{{ $enctype }}" @endif>
        @csrf
        @unless (strtoupper($method) === 'POST')@method(strtoupper($method))@endunless
        <input type="hidden" name="_modal" value="{{ $id }}">
        {{ $slot }}
        <div class="flex justify-end gap-2 {{ str_contains($formClass, 'grid-cols-2') ? 'sm:col-span-2' : '' }}">
            <x-signal.ui.button type="submit" :variant="$submitVariant">{{ $submit ?? $title }}</x-signal.ui.button>
        </div>
    </form>
</x-signal.overlays.modal>
