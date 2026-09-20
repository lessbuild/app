@props([
    'id',
    'route',
    'title',
    'description',
])

<x-dialogs.modal
    :id="$id"
    :title="$title"
    :description="$description"
    body-class="p-0"
>
    <form action="{{ $route }}" method="POST">
        @method('DELETE')
        @csrf
        <div class="flex items-start gap-4 px-5 py-5 sm:px-6">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600" aria-hidden="true">
                <svg class="h-5 w-5">
                    <use xlink:href="/assets/images/icons.svg#information-circle"></use>
                </svg>
            </span>
            <p class="text-sm text-secondary">{{ __('This action cannot be undone.') }}</p>
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-6">
            <x-ui.button type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
