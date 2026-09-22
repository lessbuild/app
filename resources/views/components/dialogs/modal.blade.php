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
    data-modal-sheet
    data-modal-initial-open="{{ $open ? 'true' : 'false' }}"
    aria-labelledby="{{ $titleId }}"
    @if ($description) aria-describedby="{{ $descriptionId }}" @endif
    @if ($open) open @endif
    {{ $attributes->class(['ui-modal']) }}
>
    <div class="ui-modal__viewport">
        <div class="ui-modal__panel ui-panel" data-modal-panel>
            <header data-modal-header class="ui-modal__header flex items-start justify-between gap-4 border-b border-line bg-surface px-5 py-4 sm:px-6">
                <div class="min-w-0">
                    <h2 id="{{ $titleId }}" tabindex="-1" class="text-lg font-black text-ink">{{ $title }}</h2>
                    @if ($description)
                        <p id="{{ $descriptionId }}" class="mt-1 text-sm text-muted">{{ $description }}</p>
                    @endif
                </div>
                <form method="dialog">
                    <button
                        type="submit"
                        class="ui-icon-btn ui-btn ui-btn-quiet min-h-10 min-w-10 px-2 text-xl leading-none"
                        data-modal-close
                        autofocus
                        aria-label="{{ __('Close :title', ['title' => $title]) }}"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </form>
            </header>
            <div data-modal-body class="ui-modal__body {{ $bodyClass }}">
                {{ $slot }}
            </div>
        </div>
    </div>
</dialog>
