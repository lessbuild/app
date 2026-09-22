@props([
    'eyebrow' => null,
    'title' => null,
    'description' => null,
    'icon' => null,
])

<section {{ $attributes->class(['ui-page-header', 'mb-8', 'flex', 'flex-col', 'justify-between', 'gap-5', 'sm:flex-row', 'sm:items-end']) }} data-ui-page-header>
    <div class="ui-page-header__layout flex min-w-0 flex-1 flex-col justify-between gap-5 sm:flex-row sm:items-end">
        <div class="ui-page-header__identity flex min-w-0 max-w-3xl items-start gap-4">
            @if ($icon)
                <span class="ui-page-header__icon mt-2 hidden h-10 w-10 shrink-0 place-items-center rounded-card bg-primary-soft text-primary sm:grid" aria-hidden="true">
                    <svg class="h-5 w-5 stroke-2">
                        <use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use>
                    </svg>
                </span>
            @endif
            <div class="min-w-0">
                @if ($eyebrow)
                    <p class="ui-page-header__eyebrow ui-eyebrow">{{ $eyebrow }}</p>
                @endif
                @if ($title)
                    <h1 class="ui-page-header__title mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ $title }}</h1>
                @endif
                @if ($description)
                    <p class="ui-page-header__description mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $description }}</p>
                @endif
            </div>
        </div>

        @isset($actions)
            <div data-ui-page-header-actions class="ui-page-header__actions">
                {{ $actions }}
            </div>
        @endisset
    </div>
</section>
