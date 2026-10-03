@props([
    'id',
    'title',
    'description' => null,
    'eyebrow' => null,
])

@php($titleId = $id.'-title')
@php($descriptionId = $id.'-description')

<aside
    id="{{ $id }}"
    class="ui-sheet hidden max-h-dvh overflow-y-auto p-5 sm:p-6"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="{{ $titleId }}"
    @if ($description) aria-describedby="{{ $descriptionId }}" @endif
    tabindex="-1"
    data-signal-side-sheet
    {{ $attributes }}
>
    <header class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="ui-eyebrow">{{ $eyebrow }}</p>
            @endif
            <h2 id="{{ $titleId }}" class="mt-2 text-xl font-extrabold text-ink">{{ $title }}</h2>
            @if ($description)
                <p id="{{ $descriptionId }}" class="mt-2 max-w-sm text-sm leading-6 text-muted">{{ $description }}</p>
            @endif
        </div>
        <x-signal.ui.icon-button :label="__('Close :title', ['title' => $title])" data-sheet-close>
            <x-signal.ui.icon name="x" class="h-5 w-5" />
        </x-signal.ui.icon-button>
    </header>

    <div class="mt-6">
        {{ $slot }}
    </div>
</aside>
