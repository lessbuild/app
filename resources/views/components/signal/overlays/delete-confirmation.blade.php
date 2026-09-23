@props([
    'id',
    'route',
    'title',
    'description',
])

<x-signal.overlays.modal
    :id="$id"
    :title="$title"
    :description="$description"
    body-class="p-0"
>
    <form action="{{ $route }}" method="POST">
        @method('DELETE')
        @csrf
        <div class="flex items-start gap-4 px-5 py-5 sm:px-6">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-line bg-surface-muted" style="color: var(--ui-danger)" aria-hidden="true">
                <svg class="h-5 w-5">
                    <use xlink:href="/assets/images/icons.svg#information-circle"></use>
                </svg>
            </span>
            <p class="text-sm text-muted">{{ __('This action cannot be undone.') }}</p>
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
            <x-signal.ui.button type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="danger">{{ __('Delete') }}</x-signal.ui.button>
        </div>
    </form>
</x-signal.overlays.modal>
