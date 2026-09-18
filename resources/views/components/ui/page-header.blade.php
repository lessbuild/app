@props([
    'title' => null,
    'description' => null,
    'icon' => null,
])

<section {{ $attributes->merge(['class' => 'ui-page-header']) }}>
    <div class="ui-page-header__layout">
        <div class="ui-page-header__identity">
            @if ($icon)
                <svg class="ui-page-header__icon" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use>
                </svg>
            @endif
            <div class="min-w-0">
                @if ($title)
                    <h1 class="ui-page-header__title">{{ $title }}</h1>
                @endif
                @if ($description)
                    <p class="ui-page-header__description">{{ $description }}</p>
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
