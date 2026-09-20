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
        <span class="text-lg leading-none text-secondary" aria-hidden="true">+</span>
    </span>
</button>

<dialog
    id="{{ $dialogId }}"
    data-filter-dialog
    data-modal-sheet
    data-filter-initial-open="{{ $open ? 'true' : 'false' }}"
    aria-labelledby="{{ $titleId }}"
    @if ($open) open @endif
    {{ $dialogAttributes->class(['ui-filter-dialog']) }}
>
    <div class="ui-filter-dialog__viewport">
        <div class="ui-filter-dialog__panel">
            <header class="ui-filter-dialog__header flex items-center justify-between gap-4 border-b border-primary px-4 py-3 lg:px-5">
                <div class="min-w-0">
                    <h2 id="{{ $titleId }}" tabindex="-1" class="font-bold text-primary">{{ $label }}</h2>
                    @if ($summary)
                        <p class="mt-1 text-xs text-secondary">{{ $summary }}</p>
                    @endif
                </div>
                <form method="dialog" class="lg:hidden">
                    <button
                        type="submit"
                        data-filter-dialog-close
                        class="button button--ghost min-h-10 min-w-10 px-2 text-xl leading-none"
                        aria-label="{{ __('Close filters') }}"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </form>
            </header>
            <div class="ui-filter-dialog__body border-t border-primary p-4 lg:border-t-0">
                {{ $slot }}
            </div>
        </div>
    </div>
</dialog>
