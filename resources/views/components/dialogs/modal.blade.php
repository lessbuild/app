@props([
    'id',
    'title',
    'description' => null,
    'open' => false,
    'bodyClass' => 'px-5 py-5 sm:px-6',
])

@php($titleId = $id.'-title')
@php($descriptionId = $id.'-description')

<dialog
    id="{{ $id }}"
    data-modal-initial-open="{{ $open ? 'true' : 'false' }}"
    aria-labelledby="{{ $titleId }}"
    @if ($description) aria-describedby="{{ $descriptionId }}" @endif
    @if ($open) open @endif
    {{ $attributes->class(['ui-modal']) }}
>
    <div class="ui-modal__viewport">
        <div class="ui-modal__panel">
            <header class="flex items-start justify-between gap-4 border-b border-primary px-5 py-4 sm:px-6">
                <div class="min-w-0">
                    <h2 id="{{ $titleId }}" tabindex="-1" class="text-lg font-black text-primary">{{ $title }}</h2>
                    @if ($description)
                        <p id="{{ $descriptionId }}" class="mt-1 text-sm text-secondary">{{ $description }}</p>
                    @endif
                </div>
                <form method="dialog">
                    <button
                        type="submit"
                        class="button button--ghost min-h-10 min-w-10 px-2 text-xl leading-none"
                        data-modal-close
                        autofocus
                        aria-label="{{ __('Close :title', ['title' => $title]) }}"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </form>
            </header>
            <div class="ui-modal__body {{ $bodyClass }}">
                {{ $slot }}
            </div>
        </div>
    </div>
</dialog>
