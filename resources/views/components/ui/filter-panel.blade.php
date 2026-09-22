@props([
    'open' => false,
    'label' => __('Filters'),
    'summary' => null,
])

@php($dialogId = (string) $attributes->get('id', 'filters'))
@php($titleId = $dialogId.'-title')
@php($dialogAttributes = $attributes->except('id'))

<button
    type="button"
    data-filter-dialog-trigger
    aria-controls="{{ $dialogId }}"
    aria-expanded="{{ $open ? 'true' : 'false' }}"
    {{ $dialogAttributes->class(['ui-filter-dialog-trigger', 'lg:hidden']) }}
>
    <span>{{ $label }}</span>
    <span class="flex items-center gap-2">
        @if ($summary)
            <x-ui.badge tone="accent">{{ $summary }}</x-ui.badge>
        @endif
        <span class="text-lg leading-none text-muted" aria-hidden="true">+</span>
    </span>
</button>

<dialog
    id="{{ $dialogId }}"
    data-filter-dialog
    data-modal-sheet
    data-filter-initial-open="{{ $open ? 'true' : 'false' }}"
    aria-labelledby="{{ $titleId }}"
    @if ($open) open @endif
    {{ $dialogAttributes->class(['ui-dialog', 'ui-filter-dialog']) }}
>
    <div data-filter-panel>
        <header class="flex items-center justify-between gap-4 border-b border-line p-4 lg:px-5">
            <div class="min-w-0">
                <h2 id="{{ $titleId }}" tabindex="-1" class="font-extrabold text-ink">{{ $label }}</h2>
                @if ($summary)
                    <p class="mt-1 text-xs text-muted">{{ $summary }}</p>
                @endif
            </div>
            <form method="dialog" class="lg:hidden">
                <button
                    type="submit"
                    data-filter-dialog-close
                    class="ui-icon-btn"
                    aria-label="{{ __('Close filters') }}"
                >
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true">
                        <use xlink:href="/assets/images/icons.svg#close"></use>
                    </svg>
                </button>
            </form>
        </header>
        <div data-filter-dialog-body class="border-t border-line p-4 lg:border-t-0">
            {{ $slot }}
        </div>
    </div>
</dialog>
