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
    {{ $attributes->class(['ui-dialog']) }}
>
    <div data-modal-panel>
        <header data-modal-header class="flex items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
            <div class="min-w-0">
                <h2 id="{{ $titleId }}" tabindex="-1" class="text-lg font-extrabold text-ink">{{ $title }}</h2>
                @if ($description)
                    <p id="{{ $descriptionId }}" class="mt-1 text-sm text-muted">{{ $description }}</p>
                @endif
            </div>
            <form method="dialog">
                <button
                    type="submit"
                    class="ui-icon-btn"
                    data-modal-close
                    autofocus
                    aria-label="{{ __('Close :title', ['title' => $title]) }}"
                >
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true">
                        <use xlink:href="/assets/images/icons.svg#close"></use>
                    </svg>
                </button>
            </form>
        </header>
        <div data-modal-body class="{{ $bodyClass }}">
            {{ $slot }}
        </div>
    </div>
</dialog>
