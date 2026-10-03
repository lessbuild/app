@props([
    'id',
    'open' => false,
])

<dialog
    id="{{ $id }}"
    data-modal-sheet
    data-modal-initial-open="{{ $open ? 'true' : 'false' }}"
    @if ($open) open @endif
    {{ $attributes->class(['ui-dialog']) }}
>
    <div data-modal-panel>{{ $slot }}</div>
</dialog>
